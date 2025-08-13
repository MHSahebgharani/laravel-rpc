<?php

namespace RabbitRPC\Client;

use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use RabbitRPC\Contracts\Client as ClientContract;
use RabbitRPC\Support\RabbitConnectionFactory;

class RpcClient implements ClientContract
{
    protected array $cfg;
    protected static int $failCount = 0;
    protected static ?int $openedAt = null;

    public function __construct(array $cfg = null)
    {
        $this->cfg = $cfg ?? config('rpc');
    }

    public function call(string $endpoint, string $route, array $payload = [], ?float $timeoutSeconds = null): array
    {
        $this->guardBreaker();

        $timeout  = $timeoutSeconds ?? (float) $this->cfg['timeout_seconds'];
        $attempts = 1 + (int) $this->cfg['retries'];
        $delayMs  = (int) $this->cfg['retry_delay_ms'];
        $queue    = $this->cfg['endpoints'][$endpoint] ?? $endpoint;
        $lastErr  = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $res = $this->attemptOnce($queue, $route, $payload, $timeout);
                self::$failCount = 0;
                return $res;
            } catch (\Throwable $e) {
                $lastErr = $e;
                self::$failCount++;
                if ($i < $attempts - 1) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $this->maybeOpenBreaker();
        throw new \RuntimeException('RPC failed: ' . ($lastErr?->getMessage() ?? 'unknown'));
    }

    protected function attemptOnce(string $queue, string $route, array $payload, float $timeout): array
    {
        $conn = RabbitConnectionFactory::make();
        $ch   = $conn->channel();

        $replyTo = 'amq.rabbitmq.reply-to';
        $corrId  = Uuid::uuid4()->toString();
        $response = null;

        $ch->basic_consume($replyTo, '', false, true, false, false, function ($msg) use ($corrId, &$response) {
            if ($msg->get('correlation_id') === $corrId) {
                $response = $msg->getBody();
            }
        });

        $envelope = [
            'route'   => $route,
            'payload' => $payload,
            'meta'    => [
                'request_id' => $corrId,
                'app'        => config('app.name'),
                'ts'         => microtime(true),
            ],
        ];

        $message = new AMQPMessage(json_encode($envelope, JSON_UNESCAPED_UNICODE), [
            'correlation_id' => $corrId,
            'reply_to'       => $replyTo,
            'content_type'   => 'application/json',
            'delivery_mode'  => 1,
        ]);

        $ch->basic_publish($message, '', $queue);

        $start = microtime(true);
        while ($response === null) {
            $left = $timeout - (microtime(true) - $start);
            if ($left <= 0) {
                $ch->close();
                $conn->close();
                throw new \RuntimeException("RPC timeout after {$timeout}s");
            }
            $ch->wait(null, false, max(0.1, $left));
        }

        $ch->close();
        $conn->close();

        $decoded = json_decode($response, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid RPC JSON response');
        }
        if (($decoded['error'] ?? null) !== null) {
            throw new \RuntimeException('Remote error: ' . $decoded['error']);
        }
        return $decoded['data'] ?? $decoded;
    }

    protected function guardBreaker(): void
    {
        $th = (int) $this->cfg['cb']['failure_threshold'];
        $open = (int) $this->cfg['cb']['open_seconds'];

        if (self::$openedAt && (time() - self::$openedAt) < $open) {
            throw new \RuntimeException('RPC circuit open; try later');
        }
        if (self::$failCount >= $th) {
            self::$openedAt = time();
            throw new \RuntimeException('RPC circuit open; try later');
        }
    }

    protected function maybeOpenBreaker(): void
    {
        $th = (int) $this->cfg['cb']['failure_threshold'];
        if (self::$failCount >= $th && ! self::$openedAt) {
            self::$openedAt = time();
        }
    }
}
