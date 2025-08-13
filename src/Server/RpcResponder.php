<?php

namespace RabbitRPC\Server;

use PhpAmqpLib\Message\AMQPMessage;
use RabbitRPC\Contracts\Responder as ResponderContract;
use RabbitRPC\Support\RabbitConnectionFactory;

class RpcResponder implements ResponderContract
{
    protected string $queue;
    protected bool $durable;
    protected array $handlers = [];

    public function __construct(string $queue, bool $durable = true)
    {
        $this->queue = $queue;
        $this->durable = $durable;
    }

    public function handle(string $route, callable $callable): self
    {
        $this->handlers[$route] = $callable;
        return $this;
    }

    public function consume(): void
    {
        $conn = RabbitConnectionFactory::make();
        $ch   = $conn->channel();

        $ch->queue_declare($this->queue, false, $this->durable, false, false);
        $ch->basic_qos(null, 1, null);

        $ch->basic_consume($this->queue, '', false, false, false, false, function ($req) use ($ch) {
            $replyTo = $req->get('reply_to');
            $corrId  = $req->get('correlation_id');

            $status = 200;
            $payload = null;
            $error = null;

            try {
                $body  = json_decode($req->getBody(), true) ?: [];
                $route = $body['route'] ?? '';
                $data  = $body['payload'] ?? [];
                $meta  = $body['meta'] ?? [];

                if (! isset($this->handlers[$route])) {
                    throw new \RuntimeException("No handler for route: {$route}");
                }

                $payload = ($this->handlers[$route])($data, $meta);
            } catch (\Throwable $e) {
                report($e);
                $status = 500;
                $error = $e->getMessage();
            }

            $response = new AMQPMessage(json_encode([
                'status' => $status,
                'data'   => $payload,
                'error'  => $error,
            ], JSON_UNESCAPED_UNICODE), [
                'correlation_id' => $corrId,
                'content_type'   => 'application/json',
                'delivery_mode'  => 1,
            ]);

            $ch->basic_ack($req->getDeliveryTag());
            if ($replyTo) {
                $ch->basic_publish($response, '', $replyTo);
            }
        });

        while ($ch->is_consuming()) {
            $ch->wait();
        }

        $ch->close();
        $conn->close();
    }
}
