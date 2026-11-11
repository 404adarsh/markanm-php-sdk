# MarkanM PHP SDK

The official PHP SDK for building bots on the MarkanM Chat Bot Platform. It provides a small, dependency-free client for sending messages, receiving bot events, handling commands and signed webhooks, and optionally calling an OpenAI-compatible chat completions API.

- **Package:** [`markanm/bot`](https://packagist.org/packages/markanm/bot)
- **PHP:** 8.1 or newer
- **Runtime dependencies:** none (uses PHP stream wrappers; cURL is not required)
- **API reference:** [MarkanM developer documentation](https://chat.markanm.com/developers/docs)
- **License:** MIT

## Contents

- [Installation](#installation)
- [Requirements and configuration](#requirements-and-configuration)
- [Quick start: polling bot](#quick-start-polling-bot)
- [Commands and events](#commands-and-events)
- [Sending messages](#sending-messages)
- [Webhooks](#webhooks)
- [API client options](#api-client-options)
- [Optional AI helper](#optional-ai-helper)
- [Errors and retries](#errors-and-retries)
- [Deployment guidance](#deployment-guidance)
- [Troubleshooting](#troubleshooting)
- [Current API coverage and limitations](#current-api-coverage-and-limitations)
- [Contributing and releases](#contributing-and-releases)

## Installation

Install the package from [Packagist](https://packagist.org/packages/markanm/bot):

```bash
composer require markanm/bot
```

Then load Composer's autoloader in your application:

```php
require __DIR__ . '/vendor/autoload.php';

use MarkanM\Bot\Bot;
```

## Requirements and configuration

The SDK requires PHP 8.1+, the JSON extension, and outbound HTTPS access. PHP's `allow_url_fopen` setting must allow HTTPS stream requests. A working CA certificate bundle and correct system clock are also needed for TLS verification and webhook timestamp checks.

Create a bot in MarkanM and copy its token into an environment variable. Do not put credentials in source code, commit them, or expose them in browser-side JavaScript.

```bash
# Linux/macOS shell (temporary for this process)
export MARKANM_BOT_TOKEN="your-bot-token"

# PowerShell (temporary for this process)
$env:MARKANM_BOT_TOKEN = "your-bot-token"
```

For production, configure secrets through your hosting provider or process manager. The examples below read the token using `getenv()`.

## Quick start: polling bot

Create `bot.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MarkanM\Bot\Bot;
use MarkanM\Bot\Event;

$token = getenv('MARKANM_BOT_TOKEN');
if ($token === false || $token === '') {
    throw new RuntimeException('Set the MARKANM_BOT_TOKEN environment variable.');
}

$bot = new Bot($token);

$bot->command('hello', function ($ctx): void {
    $name = $ctx->user['display_name'] ?? 'there';
    $ctx->reply("Hello, {$name}!");
});

$bot->on('command.received', function (Event $event): void {
    error_log(sprintf('Received event %s (%s)', $event->id, $event->type));
});

// Poll every 2 seconds. Keep this PHP process running.
$bot->runPolling(2000);
```

Run it from the project directory:

```bash
php bot.php
```

`runPolling()` keeps running until `stop()` is called or the process is stopped. `pollOnce()` is available when you want to control the polling schedule yourself. Polling is useful for development and simple deployments. For production, prefer webhooks when your service can receive HTTPS requests. The server marks a fetched batch delivered before local handlers finish, so make handlers safe to retry and make external side effects idempotent.

## Commands and events

Register a command with `command(string $name, callable $handler)`. A leading slash is optional; command names are normalized to lowercase.

```php
$bot->command('echo', function ($ctx): void {
    $ctx->reply($ctx->text);
});
```

`CommandContext` provides these properties:

| Property | Type | Description |
| --- | --- | --- |
| `command` | `string` | Command name without a leading slash |
| `text` | `string` | Text supplied with the command, when present |
| `args` | `array` | Parsed command arguments, or arguments supplied in the event |
| `user` | `array` | User data from the event |
| `room` | `array` | Room data from the event |
| `roomId` | `?string` | Room ID used by `reply()` |
| `message` | `array` | Message data from the event |
| `rawData` | `array` | Original command payload |

User, room and message data are arrays. For example, read a display name with `$ctx->user['display_name'] ?? 'there'`. `reply(string $text, ?array $card = null)` sends a message to the context's room and throws if no room ID is present.

Listen for a specific event type with `on(string $type, callable $handler)`. The SDK dispatches listeners before running a matching command handler.

```php
$bot->on('message.created', function (Event $event): void {
    error_log('Event payload: ' . json_encode($event->payload));
});
```

An `Event` has `id`, `type`, `timestamp`, `payload`, and `rawData` properties. Payload shape depends on the event produced by the platform; check for optional fields before using them.

## Sending messages

Call `sendMessage()` with a room ID and text:

```php
$result = $bot->sendMessage('12345', 'Hello from my PHP bot');
```

The second argument may also be an array containing the request fields accepted by the bot messages endpoint. The optional third argument supplies card data:

```php
$bot->sendMessage('12345', 'Here is an update', [
    'title' => 'Status',
    'description' => 'Your task is complete.',
]);
```

Card rendering and supported fields depend on the platform API. At present, the backend stores card content as formatted text; interactive card callbacks are not supported.

## Webhooks

Webhooks avoid a long-running polling process. Configure the bot's webhook URL and shared webhook secret in the platform, then pass the exact request body and signature header to `handleWebhook()`.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MarkanM\Bot\Bot;
use MarkanM\Bot\WebhookException;

$token = getenv('MARKANM_BOT_TOKEN');
$secret = getenv('MARKANM_WEBHOOK_SECRET');
if (!$token || !$secret) {
    http_response_code(500);
    exit('Bot configuration is missing');
}

$bot = new Bot($token, $secret);
$bot->command('hello', function ($ctx): void {
    $ctx->reply('Hello!');
});

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MARKANM_SIGNATURE'] ?? '';

try {
    $bot->handleWebhook($rawBody === false ? '' : $rawBody, $signature);
    http_response_code(200);
    echo 'ok';
} catch (WebhookException $error) {
    http_response_code(400);
    error_log('Rejected MarkanM webhook: ' . $error->getMessage());
    echo 'invalid webhook';
}
```

The signature header format is `X-MarkanM-Signature: t=UNIX_SECONDS,v1=HEX_HMAC_SHA256`. The HMAC-SHA256 input is the timestamp, a period, and the **exact raw request body**. The default timestamp tolerance is 300 seconds. Do not decode and re-encode JSON before verification: whitespace or key-order changes produce a different signature. Keep the webhook secret private and expose the endpoint only over HTTPS.

`handleWebhook()` verifies the signature, parses the JSON event, dispatches listeners, and runs any matching command. Your web framework can be used instead of PHP's built-in request globals, as long as you provide the original raw body and signature header.

## API client options

The `Bot` constructor is:

```php
new Bot(
    string $token,
    ?string $webhookSecret = null,
    string $baseUrl = 'https://chat.markanm.com/api/bot/v1',
    int $timeout = 10,
);
```

Set `baseUrl` to the full API base URL for a self-hosted deployment, including `/api/bot/v1`. The timeout is in seconds.

Available methods:

```php
$profile = $bot->getMe();                    // GET /me
$message = $bot->sendMessage('123', 'Hi');  // POST /rooms/{roomId}/messages
$events = $bot->pollOnce();                  // POST /polling
$bot->dispatchEvent($eventArray);           // Dispatch a local event
$bot->stop();                                // End runPolling() after current wait
```

The SDK uses bearer-token authentication and JSON requests. No separate HTTP package is required.

## Optional AI helper

`MarkanM\Bot\AI` is an optional helper for an OpenAI-compatible chat completions endpoint. It uses the key you provide; it is separate from the MarkanM bot API and provider usage may incur separate charges.

```php
use MarkanM\Bot\AI;

$ai = new AI(getenv('OPENAI_API_KEY'), 'gpt-4o-mini');
$answer = $ai->generate('Suggest a friendly greeting'); // One prompt, no saved history

$reply = $ai->chat('I am building a bot.'); // Keeps this instance's conversation history
$followUp = $ai->chat('Suggest a short name for it.');
$ai->reset(); // Clear this instance's in-memory history
```

The constructor accepts an API key, model name, API base URL (default `https://api.openai.com/v1`), and timeout in seconds. `generate()` is stateless. `chat()` retains history only in the current PHP object; it is not persistent storage and should not be shared across unrelated users. Keep AI provider credentials on the server.

## Errors and retries

All SDK exceptions extend `MarkanMException`:

- `APIException` represents an unsuccessful API response and exposes `statusCode`, `errorCode`, `requestId`, and `retryAfter`.
- `AuthException` represents HTTP 401.
- `PermissionException` represents HTTP 403.
- `NotFoundException` represents HTTP 404.
- `RateLimitException` represents HTTP 429. Check `retryAfter` before retrying.
- `WebhookException` represents an invalid webhook secret, timestamp, signature, or JSON body.

Handle expected failures at the application boundary and log request IDs to help investigate server-side errors. Do not retry every error blindly: correct invalid credentials or permissions, and use a bounded delay for rate limits and transient transport failures.

```php
use MarkanM\Bot\APIException;
use MarkanM\Bot\RateLimitException;

try {
    $bot->sendMessage('12345', 'Hello');
} catch (RateLimitException $error) {
    error_log('Rate limited; retry after ' . $error->retryAfter . ' seconds');
} catch (APIException $error) {
    error_log(sprintf(
        'MarkanM API error %d [%s], request %s: %s',
        $error->statusCode,
        $error->errorCode,
        $error->requestId ?? 'unknown',
        $error->getMessage(),
    ));
}
```

## Deployment guidance

- Use webhooks for production when you can host a public HTTPS endpoint; verify signatures on every request.
- If using polling, run `php bot.php` as a supervised long-lived process (for example, with your hosting platform's worker service or a process manager). Do not rely on a short-lived web request to keep polling.
- Keep bot tokens, webhook secrets, and AI keys in environment or secret-manager configuration.
- Make handlers idempotent. A handler may complete only partially if the process stops after an event has been fetched.
- Log errors and request IDs, but avoid logging credentials or sensitive message contents.

## Troubleshooting

### `allow_url_fopen` is disabled

The SDK uses PHP's HTTPS stream wrappers for API calls. Enable `allow_url_fopen` in the PHP configuration used by the CLI or web server, then restart the relevant process. Check the CLI configuration separately with `php --ini` if polling works differently from web requests.

### TLS or certificate errors

Install or update the operating system's CA certificates and ensure PHP is configured to use the CA bundle. Check that the server clock is correct. Do not disable TLS certificate verification to work around this issue.

### Network request failures or timeouts

Confirm DNS resolution, outbound HTTPS access to the API host, proxy/firewall configuration, and the configured timeout. On restricted hosts (including some minimal or security-focused Linux distributions), outbound connections may be blocked by firewall policy; permit the required HTTPS endpoint in accordance with that host's policy.

### HTTP 401 or 403

Confirm the token belongs to the intended bot, is current, and is supplied as `MARKANM_BOT_TOKEN`. A valid token may still lack permission for the requested action.

### HTTP 404

Check the room ID and API base URL. For self-hosted instances, the base URL must include the API version path, typically `/api/bot/v1`.

### HTTP 429

The service is rate-limiting requests. Respect the exception's `retryAfter` value and avoid tight retry loops.

### Webhooks are rejected

Use the configured secret, correct server clock, and exact bytes received from `php://input`. Read the body only once and do not parse or re-encode it before calling `handleWebhook()`. Confirm the `X-MarkanM-Signature` header reaches PHP as `HTTP_X_MARKANM_SIGNATURE`.

### Composer cannot find the package or a new version

Confirm the package name is `markanm/bot`, Packagist has indexed the repository, and the version is represented by a Git tag. Packagist currently reports this package as not auto-updated; configure its GitHub hook or trigger an update from the Packagist package page after pushing changes.

## Current API coverage and limitations

This SDK currently wraps the bot endpoints implemented by the platform: `GET /me`, `POST /rooms/{roomId}/messages`, and `POST /polling`, with local event/command dispatch and signed webhook verification. It does not currently provide wrappers for unrelated platform resources such as managing users, reactions, or interactive callback workflows. Message cards are accepted by the SDK, but the current backend presents card content as formatted text and does not provide interactive callbacks. Consult the [MarkanM developer documentation](https://chat.markanm.com/developers/docs) for current platform behavior.

## Contributing and releases

The package source is maintained in [markanm-php-sdk](https://github.com/404adarsh/markanm-php-sdk). Before submitting a change, review the source and metadata and validate the Composer manifest:

```bash
composer validate --strict
composer dump-autoload
```

Packagist reads package versions from Git tags. For a release, update the package as needed, commit the changes, create a semantic version tag (for example, `0.1.1`), push the branch and tag, and ensure Packagist has refreshed the repository. Set up the Packagist GitHub hook so pushes are detected automatically. After adding or correcting the hook, push a new commit to trigger a fresh Packagist update; confirm the delivery succeeds in GitHub repository Settings → Webhooks and check the package update log on Packagist.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
