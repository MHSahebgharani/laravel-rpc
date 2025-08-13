<?php

namespace RabbitRPC\Console; // or your package namespace

use Illuminate\Console\Command;
use RabbitRPC\Server\RpcResponder; // adjust import if different

class RpcConsumeCommand extends Command
{
    protected $signature = 'rpc:consume
        {endpoint : Endpoint key from config/rpc.php (e.g. user) OR a raw queue name}
        {--route=* : (optional) route=FQCN@method to override/add routes}';

    protected $description = 'Consume RPC requests for the given endpoint (queue)';

    public function handle(): int
    {
        $endpoint = (string) $this->argument('endpoint');
        $queue = (string) config("rpc.endpoints.$endpoint", $endpoint);

        $responder = new RpcResponder($queue);

        // 1) Load routes from routes file if present: routes/rpc_<endpoint>.php
        $filePath = base_path("routes/rpc_{$endpoint}.php");
        if (is_file($filePath)) {
            $this->registerRoutesArray($responder, require $filePath, "routes/rpc_{$endpoint}.php");
        }

        // 2) Load routes from config: config('rpc.routes.<endpoint>')
        $configRoutes = (array) config("rpc.routes.$endpoint", []);
        $this->registerRoutesArray($responder, $configRoutes, "config('rpc.routes.$endpoint')");

        // 3) CLI overrides/additions: --route="user.create=App\Services\UserService@create"
        foreach ((array) $this->option('route') as $pair) {
            if (!str_contains($pair, '=')) {
                $this->warn("Ignoring invalid --route option: {$pair}");
                continue;
            }
            [$route, $target] = explode('=', $pair, 2);
            $this->registerOne($responder, $route, $target, 'CLI --route');
        }

        // Always include ping
        $responder->handle('ping', fn() => [
            'ok' => true,
            'endpoint' => $endpoint,
            'at' => now()->toISOString(),
        ]);

        $this->info("RPC responder listening on queue: {$queue}");
        $responder->consume();
        return self::SUCCESS;
    }

    /**
     * @param array<string,string> $routes
     */
    protected function registerRoutesArray(RpcResponder $responder, array $routes, string $sourceLabel): void
    {
        foreach ($routes as $route => $target) {
            $this->registerOne($responder, (string)$route, (string)$target, $sourceLabel);
        }
    }

    protected function registerOne(RpcResponder $responder, string $route, string $target, string $source): void
    {
        if (str_contains($target, '@')) {
            [$class, $method] = explode('@', $target, 2);
            $responder->handle($route, function (array $p, array $meta = []) use ($class, $method) {
                return app($class)->{$method}($p, $meta);
            });
        } else {
            // Invokable class or container callable
            $responder->handle($route, fn(array $p, array $meta = []) => app($target)($p, $meta));
        }
        $this->line("• Registered <info>{$route}</info> from <comment>{$source}</comment> → <fg=blue>{$target}</>");
    }
}
