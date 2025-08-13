# Laravel RabbitMQ RPC (RabbitRPC)

A simple **Request/Reply (RPC)** layer for Laravel microservices over **RabbitMQ**, built on top of `php-amqplib`.
It lets your Laravel services call each other **synchronously** (like a local function) without HTTP.

---

## Features

- 🐇 Works with **RabbitMQ** (supports direct-reply-to for low-latency RPC)
- ♻ Reuses your existing `queue.connections.rabbitmq` config (compatible with `vladimir-yuldashev/laravel-queue-rabbitmq`)
- ⏱ Built-in timeouts, retries, and circuit breaker
- 📦 Easy route registration (via config, routes file, or CLI)
- 🔍 Pass arbitrary payload arrays between services
- 🛡 Supports meta info for request tracing or auth

---

## Installation

### 1) Require the package

```bash
composer require laravel-rpc-rabbitmq
```


If you are installing from a local path:

```bash
composer config repositories.rabbit-rpc path packages/laravel-rpc-rabbitmq
composer require rabbit/rpc:*
```

---

### 2) Publish the config

```bash
php artisan vendor:publish --provider="RabbitRPC\\RpcServiceProvider" --tag=config
```

This will create `config/rpc.php`.

---

## Configuration

Edit `.env` in each service:

```dotenv
# Map logical endpoints to queues
RPC_SERVICE1_QUEUE=service-1-queue
RPC_SERVICE2_QUEUE=service-2-queue

# Client defaults
RPC_TIMEOUT=3
RPC_RETRIES=0
RPC_RETRY_DELAY_MS=100

# Circuit breaker
RPC_CB_FAILS=5
RPC_CB_OPEN_SEC=15
```

And in `config/rpc.php` you can map endpoints and routes:

```php
return [
    'endpoints' => [
        'service-1'        => env('RPC_SERVICE1_QUEUE', 'service-1-queue'),
        'service-2' => env('RPC_SERVICE2_QUEUE', 'service-2-queue'),
    ],

    'timeout_seconds' => (float) env('RPC_TIMEOUT', 3.0),
    'retries'         => (int) env('RPC_RETRIES', 0),
    'retry_delay_ms'  => (int) env('RPC_RETRY_DELAY_MS', 100),

    'cb' => [
        'failure_threshold' => (int) env('RPC_CB_FAILS', 5),
        'open_seconds'      => (int) env('RPC_CB_OPEN_SEC', 15),
    ],

    'routes' => [
        'service-1' => [
           // 'service-1.create'     => 'MyService@create',
           // 'service-1.getProfile' => 'MyService@getProfile',
        ],
        'service-2' => [
            //'service-2.submit'   =>'MyService@submit',
        ],
    ],
];
```

---

## Defining RPC Routes

### Option 1 — Config map
Add to `config/rpc.php` under `'routes'`.

### Option 2 — Per-endpoint routes file
Create `routes/rpc_user.php`:

```php
use App\\Services\\UserService;

return [
    'user.create' => UserService::class.'@create',
    'user.getProfile' => UserService::class.'@getProfile',
];
```

### Option 3 — CLI flag
```bash
php artisan rpc:consume user --route="user.create=App\\Services\\UserService@create"
```

---

## Running the Responder

A **responder** listens on a queue for requests and returns a reply.

Example: In `user_service`, to expose `user.create`:

```bash
php artisan rpc:consume user
```

This will load all routes for `user` from config or `routes/rpc_user.php`.

Your handler method:

```php
namespace App\\Services;

class UserService
{
    public function create(array $params): array
    {
        // $params = ['name' => ..., 'email' => ...]
        $user = User::create([
            'name' => $params['name'],
            'email' => $params['email'],
            'phone' => $params['phone'] ?? null,
            'password' => bcrypt('secret'),
        ]);

        return $user->toArray();
    }
}
```

---

## Calling Another Service

From **accelerator_service**, call `user.create` on the `user_service`:

```php
use RabbitRPC\\Facades\\Rpc; // or DI via RabbitRPC\\Contracts\\Client

Route::post('/provision-user', function (Request $request) {
    $payload = $request->only('name', 'email', 'phone');
    $user = Rpc::call('user', 'user.create', $payload);

    return response()->json([
        'created' => true,
        'user' => $user,
    ]);
});
```

---

## Payload & Meta

The `call()` method signature:

```php
Rpc::call(string $endpoint, string $route, array $payload = [], ?float $timeoutSeconds = null): array
```

- `$endpoint` — logical key from `config/rpc.php` (`user`, `accelerator`, etc.)
- `$route` — route string that matches the responder’s handler
- `$payload` — associative array of data to pass
- `$timeoutSeconds` — optional per-call override

The responder receives:
```php
function (array $payload, array $meta) { ... }
```
`$meta` contains info like `request_id`, `app`, `ts`.

---

## Health Check

Every responder auto-registers a `ping` route:
```bash
php artisan tinker
>>> Rpc::call('user', 'ping');
# ['ok' => true, 'endpoint' => 'user', 'at' => '2025-08-13T11:00:00Z']
```

---

## Supervising

In production, run responders under Supervisor or systemd:

**supervisor.conf**
```
[program:rpc-user]
command=php artisan rpc:consume user
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/rpc-user.log
```

---

## Advanced

- **Per-route timeouts/retries:** Extend `RpcClient` to look up route-specific settings.
- **Idempotency:** Store `meta.request_id` on side-effecting routes to avoid duplicates.
- **Security:** Check `$meta` for a service token in each handler.

---

## License

MIT
