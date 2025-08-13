<?php

namespace RabbitRPC\Contracts;

interface Responder
{
    public function handle(string $route, callable $callable): self;
    public function consume(): void;
}
