<?php

namespace RabbitRPC;

use Illuminate\Support\ServiceProvider;
use RabbitRPC\Client\RpcClient;
use RabbitRPC\Console\RpcConsumeCommand;

class RpcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
//        $this->mergeConfigFrom(__DIR__.'/../config/rpc.php', 'rpc');

        $this->app->singleton('rpc.client', function () {
            return new RpcClient(config('rpc'));
        });

        $this->app->singleton(RpcConsumeCommand::class, function ($app) {
            return new RpcConsumeCommand();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/rpc.php' => config_path('rpc.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([RpcConsumeCommand::class]);
        }
    }
}
