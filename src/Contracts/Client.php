<?php

namespace RabbitRPC\Contracts;

interface Client
{
    public function call(string $endpoint, string $route, array $payload = [], ?float $timeoutSeconds = null): array;
}
