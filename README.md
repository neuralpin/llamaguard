# LlamaGuard

LlamaGuard is a PHP gateway for Ollama. Applications connect to this service instead of Ollama directly. The gateway authenticates each client, restricts its models, limits request rate and concurrency, and caps generation context and output. Client settings are encrypted in SQLite and cached in Redis.

## Requirements

- PHP 8.2 or newer with cURL, PDO SQLite, Redis, and Sodium extensions
- Composer autoload files generated with `composer install`
- Redis listening on the configured host and port (default `127.0.0.1:6379`)
- Ollama listening on the same machine at `127.0.0.1:11434` (the default)

Keep Ollama bound to loopback or a private interface that is not reachable by clients. Do not expose port `11434` through your firewall or router. Point applications only at the gateway, and make the `public` directory the web root.

## Configure

Copy `.env.example` to `.env` and set `APP_KEY` to a fresh 64-character hexadecimal key. Generate one with:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Keep `.env` and the SQLite database private and backed up separately. Losing `APP_KEY` makes encrypted client settings unrecoverable. Redis is required for authentication settings lookups; configure a password and bind Redis to a private interface when it is shared or remotely reachable. Cached settings are encrypted too.

Environment settings include `SQLITE_PATH`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_TIMEOUT`, `SETTINGS_CACHE_TTL`, `LLAMAGUARD_OLLAMA_URL` (default `http://127.0.0.1:11434`), and `LLAMAGUARD_KEEP_ALIVE` (default `5m`). Relative SQLite paths are resolved from the project root.

## Manage Clients

Initialize the database, then add each client with only the models it needs. The add command generates and displays a bearer token for a new client; deliver it to the client through a secure channel. Run it again with the same client ID to add models while keeping the existing token and limits. Explicitly supplied limits update that client's limits.

```powershell
Copy-Item .env.example .env
# Set APP_KEY in .env before initializing.
composer install
php bin/llamaguard.php init
php bin/llamaguard.php client:add reporting-app nomic-embed-text-v2-moe:latest --requests-per-minute=60 --max-concurrent=2
php bin/llamaguard.php client:list
php bin/llamaguard.php client:remove reporting-app
```

Available per-client limits are `--requests-per-minute`, `--max-concurrent`, `--max-predict`, `--max-context`, and `--max-request-bytes`. Defaults are 30 requests/minute, one concurrent request, 1,024 generated tokens, 8,192 context tokens, and 1 MiB request bodies. Values are capped by the gateway's hard limits. To grant multiple models, list them in one command or add them in successive commands using the same client ID; both use the same bearer token.

## Run

Use PHP's router-script mode for local or private deployments:

```powershell
php -S 127.0.0.1:8080 -t public public/index.php
```

For production, configure the web server to send application routes to `public/index.php` and serve only the `public` directory. Use HTTPS for remote clients; bearer tokens are credentials.

## Routes

- `GET /healthz` reports gateway process health without revealing Ollama state.
- `GET /api/tags` and `GET /api/ps` return only models allowed for the authenticated client.
- `POST /api/show`, `/api/chat`, `/api/generate`, `/api/embed`, and `/api/embeddings` require an allowed `model` in the JSON body.

Send `Authorization: Bearer <client-token>`. Ollama management operations such as pull, delete, and copy are intentionally unavailable. Generation requests have `num_predict` and `num_ctx` capped to the client's limits. Requests may set `keep_alive` to a duration string or integer, including negative durations such as `"-1h"`; `LLAMAGUARD_KEEP_ALIVE` is used when the request omits it.