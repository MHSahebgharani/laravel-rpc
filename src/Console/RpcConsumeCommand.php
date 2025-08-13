<?php

namespace RabbitRPC\Console;

use Illuminate\Console\Command;
use RabbitRPC\Server\RpcResponder;

class RpcConsumeCommand extends Command
{
    protected $signature = 'rpc:consume
        {endpoint : The endpoint key from config/rpc.php OR a raw queue name}
        {--route=* : route=FQCN@method or route=callableName}
    ';
    protected $description = 'Consume RPC requests for the given endpoint (queue)';

    public function handle(): int
    {
        $endpoint = $this->argument('endpoint');
        $queue = config("rpc.endpoints.$endpoint", $endpoint);

        $responder = new RpcResponder($queue);

        foreach ((array) $this->option('route') as $pair) {
            if (! str_contains($pair, '=')) continue;
            [$route, $target] = explode('=', $pair, 2);

            if (str_contains($target, '@')) {
                [$class, $method] = explode('@', $target, 2);
                $responder->handle($route, function (array $p) use ($class, $method) {
                    return app($class)->{$method}($p);
                });
            } else {
                $responder->handle($route, fn(array $p) => app($target)($p));
            }
        }

        $responder->handle('ping', fn() => ['ok' => true, 'at' => now()->toISOString(), 'endpoint' => $endpoint]);

        $this->info("RPC responder listening on queue: {$queue}");
        $responder->consume();
        return self::SUCCESS;
    }
}
