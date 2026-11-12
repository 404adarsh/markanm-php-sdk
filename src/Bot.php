<?php
declare(strict_types=1);

namespace MarkanM\Bot;

use RuntimeException;

class MarkanMException extends RuntimeException {}
class APIException extends MarkanMException
{
    public function __construct(string $message, public readonly int $statusCode = 0, public readonly string $errorCode = 'api_error', public readonly ?string $requestId = null, public readonly int $retryAfter = 0)
    { parent::__construct($message . ($requestId ? " (Request-ID: {$requestId})" : '')); }
}
class AuthException extends APIException {}
class PermissionException extends APIException {}
class NotFoundException extends APIException {}
class RateLimitException extends APIException {}
class WebhookException extends MarkanMException {}

final class Event
{
    public readonly string $id;
    public readonly string $type;
    public readonly mixed $payload;
    public readonly string $timestamp;
    public function __construct(public readonly array $rawData)
    {
        $this->id = (string)($rawData['event_id'] ?? $rawData['id'] ?? '');
        $this->type = (string)($rawData['event_type'] ?? $rawData['event'] ?? '');
        $this->timestamp = (string)($rawData['timestamp'] ?? $rawData['created_at'] ?? '');
        $this->payload = array_key_exists('payload', $rawData) ? $rawData['payload'] : $rawData;
    }
}

final class Client
{
    private string $baseUrl;
    public function __construct(private readonly string $token, string $baseUrl = 'https://chat.markanm.com/api/bot/v1', private readonly int $timeout = 10)
    {
        if ($token === '') throw new MarkanMException('A non-empty bot token is required');
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    private function normalizeAvatarUrls(mixed $value): mixed
    {
        $parts = parse_url($this->baseUrl) ?: [];
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . ($parts['host'] ?? 'chat.markanm.com') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === 'avatar_url' && is_string($item) && trim($item) !== '') {
                    if (str_starts_with($item, '//')) $item = $scheme . ':' . $item;
                    elseif (!preg_match('#^https?://#i', $item)) $item = $origin . '/' . ltrim($item, '/');
                }
                $value[$key] = $this->normalizeAvatarUrls($item);
            }
        }
        return $value;
    }
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = "Authorization: Bearer {$this->token}\r\nContent-Type: application/json\r\nAccept: application/json\r\nUser-Agent: markanm-php-sdk/0.1.1\r\n";
        $options = ['http' => ['method' => strtoupper($method), 'header' => $headers, 'timeout' => $this->timeout, 'ignore_errors' => true]];
        if ($body !== null) $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $last = error_get_last();
            throw new MarkanMException('Network request failed: ' . ($last['message'] ?? 'unknown transport error'));
        }
        $status = 0; $requestId = null; $retryAfter = 60;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) $status = (int)$match[1];
            if (stripos($header, 'X-MarkanM-Request-ID:') === 0) $requestId = trim(substr($header, strpos($header, ':') + 1));
            if (stripos($header, 'Retry-After:') === 0) $retryAfter = max(1, (int)trim(substr($header, strpos($header, ':') + 1)));
        }
        $data = json_decode($response, true);
        if (!is_array($data)) $data = [];
        if ($status < 200 || $status >= 300) {
            $detail = $data['error'] ?? [];
            $code = is_array($detail) ? (string)($detail['code'] ?? 'api_error') : 'api_error';
            $message = is_array($detail) ? (string)($detail['message'] ?? $response) : (string)$detail;
            $exception = match ($status) {
                401 => AuthException::class,
                403 => PermissionException::class,
                404 => NotFoundException::class,
                429 => RateLimitException::class,
                default => APIException::class,
            };
            throw new $exception("HTTP {$status} [{$code}]: {$message}", $status, $code, $requestId, $status === 429 ? $retryAfter : 0);
        }
        return $this->normalizeAvatarUrls($data);
    }
    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $body = []): array { return $this->request('POST', $path, $body); }
}

final class CommandContext
{
    public readonly string $command;
    public readonly string $text;
    public readonly array $args;
    public readonly array $user;
    public readonly array $room;
    public readonly ?string $roomId;
    public readonly array $message;
    public function __construct(private readonly Bot $bot, public readonly array $rawData)
    {
        $this->command = ltrim((string)($rawData['command'] ?? ''), '/');
        $this->text = (string)($rawData['text'] ?? $rawData['message']['text'] ?? '');
        $parts = preg_split('/\s+/', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->args = is_array($rawData['args'] ?? null) ? $rawData['args'] : array_slice($parts, 1);
        $this->user = $rawData['user'] ?? [];
        $this->room = $rawData['room'] ?? ['id' => $rawData['room_id'] ?? $rawData['conversation_id'] ?? null];
        $this->roomId = isset($this->room['id']) ? (string)$this->room['id'] : null;
        $this->message = $rawData['message'] ?? [];
    }
    public function reply(string $text, ?array $card = null): array
    {
        if (!$this->roomId) throw new MarkanMException('Cannot reply: event has no room ID');
        return $this->bot->sendMessage($this->roomId, $text, $card);
    }
}

final class Bot
{
    private Client $client;
    private array $commands = [];
    private array $listeners = [];
    private bool $running = false;
    public function __construct(string $token, private readonly ?string $webhookSecret = null, string $baseUrl = 'https://chat.markanm.com/api/bot/v1', int $timeout = 10)
    { $this->client = new Client($token, $baseUrl, $timeout); }
    public function command(string $name, callable $handler): self { $this->commands[strtolower(ltrim(trim($name), '/'))] = $handler; return $this; }
    public function on(string $type, callable $handler): self { $this->listeners[$type][] = $handler; return $this; }
    public function getMe(): array { return $this->client->get('/me'); }
    public function sendMessage(string|int $roomId, string|array $text, ?array $card = null): array
    {
        $body = is_array($text) ? $text : ['text' => $text];
        if ($card !== null) { $body['card'] = $card; $body['type'] = 'card'; }
        return $this->client->post('/rooms/' . rawurlencode((string)$roomId) . '/messages', $body);
    }
    public function dispatchEvent(Event|array $input): Event
    {
        $event = $input instanceof Event ? $input : new Event($input);
        foreach ($this->listeners[$event->type] ?? [] as $handler) $handler($event);
        $payload = is_array($event->payload) ? $event->payload : [];
        if ($event->type === 'command.received' || isset($payload['command'])) {
            $name = strtolower(ltrim(trim((string)($payload['command'] ?? '')), '/'));
            if (isset($this->commands[$name])) ($this->commands[$name])(new CommandContext($this, $payload));
        }
        return $event;
    }
    public function handleWebhook(string $rawBody, string $signature, ?string $secret = null): Event
    {
        $event = Webhook::verify($rawBody, $signature, $secret ?? $this->webhookSecret);
        $this->dispatchEvent($event); return $event;
    }
    public function pollOnce(): array
    {
        $data = $this->client->post('/polling');
        foreach ($data['events'] ?? [] as $event) $this->dispatchEvent($event);
        return $data['events'] ?? [];
    }
    public function runPolling(int $intervalMs = 2000, ?callable $onError = null): void
    {
        if ($intervalMs < 1) throw new MarkanMException('Polling interval must be greater than zero');
        $this->running = true;
        while ($this->running) {
            try { $this->pollOnce(); }
            catch (\Throwable $error) { if ($onError) $onError($error); else error_log('MarkanM polling error: ' . $error->getMessage()); }
            if ($this->running) usleep($intervalMs * 1000);
        }
    }
    public function stop(): void { $this->running = false; }
}

final class Webhook
{
    public static function verify(string $rawBody, string $signature, ?string $secret, int $tolerance = 300): Event
    {
        if (!$secret) throw new WebhookException('Webhook secret is required');
        $fields = [];
        foreach (explode(',', $signature) as $part) { $pieces = explode('=', trim($part), 2); if (count($pieces) === 2) $fields[$pieces[0]] = $pieces[1]; }
        $timestamp = filter_var($fields['t'] ?? null, FILTER_VALIDATE_INT);
        if ($timestamp === false || abs(time() - $timestamp) > $tolerance) throw new WebhookException('Webhook timestamp missing, invalid, or outside tolerance');
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!isset($fields['v1']) || !hash_equals($expected, $fields['v1'])) throw new WebhookException('Invalid webhook signature');
        try { $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new WebhookException('Webhook body is not valid JSON', 0, $error); }
        if (!is_array($data)) throw new WebhookException('Webhook JSON must be an object');
        return new Event($data);
    }
}

/** OpenAI-compatible chat completions helper, using the caller's API key. */
final class AI
{
    private array $history = [];
    public function __construct(private readonly string $apiKey, private readonly string $model = 'gpt-4o-mini', private readonly string $baseUrl = 'https://api.openai.com/v1', private readonly int $timeout = 15)
    { if ($apiKey === '') throw new MarkanMException('AI API key is required'); }
    public function generate(string $prompt, string $systemPrompt = 'You are a helpful MarkanM bot assistant.'): string
    { return $this->complete([['role'=>'system','content'=>$systemPrompt], ['role'=>'user','content'=>$prompt]]); }
    public function chat(string $message, string $systemPrompt = 'You are a helpful MarkanM bot assistant.'): string
    {
        $answer = $this->complete(array_merge([['role'=>'system','content'=>$systemPrompt]], $this->history, [['role'=>'user','content'=>$message]]));
        $this->history[] = ['role'=>'user','content'=>$message]; $this->history[] = ['role'=>'assistant','content'=>$answer]; return $answer;
    }
    public function reset(): void { $this->history = []; }
    private function complete(array $messages): string
    {
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Authorization: Bearer {$this->apiKey}\r\nContent-Type: application/json\r\n",'content'=>json_encode(['model'=>$this->model,'messages'=>$messages], JSON_THROW_ON_ERROR),'timeout'=>$this->timeout,'ignore_errors'=>true]]);
        $raw = @file_get_contents(rtrim($this->baseUrl, '/') . '/chat/completions', false, $context);
        if ($raw === false) throw new MarkanMException('AI provider network request failed');
        $data = json_decode($raw, true) ?: [];
        if (($data['choices'][0]['message']['content'] ?? null) === null) throw new MarkanMException('AI provider returned no message: ' . ($data['error']['message'] ?? 'invalid response'));
        return trim((string)$data['choices'][0]['message']['content']);
    }
}
