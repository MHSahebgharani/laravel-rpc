<?php

namespace RabbitRPC\Support;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitConnectionFactory
{
    public static function make(): AMQPStreamConnection
    {
        $cfg = config('queue.connections.rabbitmq', []);

        $host     = $cfg['hosts'][0]['host']    ;
        $port     = 5672    ;
        $vhost    = $cfg['hosts'][0]['vhost']   ;
        $user     = $cfg['hosts'][0]['user'];
        $password = $cfg['hosts'][0]['password'];

        $sslParams = $cfg['options']['ssl_options'] ?? [];
        $sslOn     = $sslParams['ssl_on'] ?? false;

        if (! $sslOn) {
            return new AMQPStreamConnection($host, $port, $user, $password, $vhost, connection_timeout: 3, read_write_timeout: 10, keepalive: true, heartbeat: 20);
        }

        return new AMQPStreamConnection(
            $host, $port, $user, $password, $vhost, false, 'AMQPLAIN', null, 'en_US',
            10.0, 10.0, null, false, $sslParams
        );
    }
}
