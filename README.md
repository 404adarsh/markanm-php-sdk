# MarkanM PHP SDK

PHP SDK for MarkanM bots. Requires PHP 8.1+, JSON, and outbound HTTPS. It uses PHP stream wrappers, so cURL is not required.

## Install

```bash
composer require markanm/bot
```

Set `MARKANM_BOT_TOKEN` in the server environment. Never commit bot or AI credentials.

## Polling example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use MarkanM\Bot\Bot;

$bot = new Bot(getenv('MARKANM_BOT_TOKEN'));
$bot->command('hello', function ($ctx) use ($bot): void {
    $ctx->reply('Hello, ' . ($ctx->user['display_name'] ?? 'there') . '!');
});
$bot->on('command.received', function ($event): void {
    error_log($event->id . ' ' . $event->type);
});
$bot->runPolling(2000);
```

Polling is intended for development and simple deployments. The backend marks a fetched batch delivered before handlers finish; use webhooks in production and make side effects idempotent.

## API

```php
$bot = new Bot($token, $webhookSecret = null, $baseUrl = 'https://chat.markanm.com/api/bot/v1', $timeout = 10);
$profile = $bot->getMe();
$sent = $bot->sendMessage('123', 'Hello');
$bot->pollOnce();
$bot->handleWebhook($exactRawBody, $signatureHeader);
```

The supported API routes are `GET /me`, `POST /rooms/{roomId}/messages`, and `POST /polling`. `sendMessage` may receive a card array, but the current backend stores card content as formatted text and has no interactive callbacks.

Register listeners with `on(string $type, callable $handler)` and commands with `command(string $name, callable $handler)`. `CommandContext` exposes `command`, `text`, `args`, `user`, `room`, `roomId`, `message` and `reply(string $text)`. An `Event` exposes `id`, `type`, `timestamp`, `payload` and `rawData`.

Webhook verification validates `X-MarkanM-Signature: t=UNIX_SECONDS,v1=HEX_HMAC_SHA256`, signed as `timestamp + "." + exact raw body`. Use the unmodified body from `file_get_contents('php://input')`; JSON re-encoding changes the signature. The default timestamp tolerance is five minutes.

## AI helper

`MarkanM\Bot\AI` calls an OpenAI-compatible chat completions endpoint using your own key:

```php
use MarkanM\Bot\AI;
$ai = new AI(getenv('OPENAI_API_KEY'), 'gpt-4o-mini');
$answer = $ai->chat('Suggest a greeting');
$ai->reset();
```

History is in memory and provider usage is billed separately. Keep the API key server-side.

## Errors and troubleshooting

`MarkanMException` is the base SDK exception. `APIException` includes `statusCode`, `errorCode`, and `requestId`; 401, 403, 404, and 429 responses use `AuthException`, `PermissionException`, `NotFoundException`, and `RateLimitException`. Rate limit errors include `retryAfter`. Network failures are thrown as `MarkanMException`. Keep TLS verification enabled; check PHP stream access (`allow_url_fopen`), CA certificates, system clock, DNS, outbound HTTPS, token, and base URL. Set the full `/api/bot/v1` URL for self-hosted instances.

## Publish to Packagist

Review `name` and repository metadata in `composer.json`, commit and push this package directory as its own repository (or configure a Composer VCS repository), then submit the repository to Packagist. Validate locally with:

```bash
composer validate --strict
composer dump-autoload
```

Tag releases with semantic versions (for example `1.0.0`) and ensure the VCS repository tag points to the package source. Packagist versions are derived from VCS tags.

## License

MIT.
