<?php

declare(strict_types=1);

namespace Neuralpin\Llamaguard;

final class OllamaGateway
{
    private const ROUTES = [
        'GET' => ['tags', 'ps'],
        'POST' => ['chat', 'generate', 'embed', 'embeddings', 'show'],
    ];

    private string $ollamaUrl;
    private ClientRegistry $clients;
    private RequestLimiter $limiter;

    public function __construct(?ClientRegistry $clients = null, ?RequestLimiter $limiter = null)
    {
        $this->ollamaUrl = rtrim(getenv('LLAMAGUARD_OLLAMA_URL') ?: 'http://127.0.0.1:11434', '/');
        $parts = parse_url($this->ollamaUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['path']) && $parts['path'] !== '') {
            throw new \RuntimeException('LLAMAGUARD_OLLAMA_URL must be an HTTP(S) origin without a path.');
        }

        $this->clients = $clients ?? new ClientRegistry();
        $this->limiter = $limiter ?? new RequestLimiter();
    }

    public function handle(array $server): void
    {
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($path === '/healthz' && $method === 'GET') {
            $this->jsonResponse(200, ['status' => 'ok']);
            return;
        }

        if (!is_string($path) || !preg_match('#^/api/([a-z]+)$#', $path, $matches)) {
            $this->jsonResponse(404, ['error' => 'Not found']);
            return;
        }

        $endpoint = $matches[1];
        if (!in_array($endpoint, self::ROUTES[$method] ?? [], true)) {
            $this->jsonResponse(404, ['error' => 'Not found']);
            return;
        }

        $client = $this->clients->authenticate($server);
        if ($client === null) {
            header('WWW-Authenticate: Bearer');
            $this->jsonResponse(401, ['error' => 'Unauthorized']);
            return;
        }

        try {
            $retryAfter = $this->limiter->consume($client['id'], $client['requests_per_minute']);
        } catch (\Throwable) {
            $this->jsonResponse(503, ['error' => 'Gateway state is unavailable']);
            return;
        }

        header('X-RateLimit-Limit: ' . $client['requests_per_minute']);
        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
            $this->jsonResponse(429, ['error' => 'Request limit exceeded']);
            return;
        }

        $payload = null;
        if ($method === 'POST') {
            $contentLength = (int) ($server['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > $client['max_request_bytes']) {
                $this->jsonResponse(413, ['error' => 'Request body is too large']);
                return;
            }

            $rawBody = file_get_contents('php://input', false, null, 0, $client['max_request_bytes'] + 1);
            if ($rawBody === false || strlen($rawBody) > $client['max_request_bytes']) {
                $this->jsonResponse(413, ['error' => 'Request body is too large']);
                return;
            }

            $payload = json_decode($rawBody, true);
            if (!is_array($payload) || array_is_list($payload)) {
                $this->jsonResponse(400, ['error' => 'Request body must be a JSON object']);
                return;
            }

            if ($endpoint !== 'show' && !$this->limitGeneration($payload, $client)) {
                $this->jsonResponse(400, ['error' => 'Invalid generation options']);
                return;
            }

            $model = $payload['model'] ?? null;
            if (!is_string($model) || !in_array($model, $this->clients->modelsFor($client), true)) {
                $this->jsonResponse(403, ['error' => 'Model is not available to this client']);
                return;
            }
        }

        $slot = $this->limiter->acquire($client['id'], $client['max_concurrent']);
        if ($slot === null) {
            header('Retry-After: 2');
            $this->jsonResponse(429, ['error' => 'Client concurrency limit reached']);
            return;
        }

        try {
            $this->proxy($method, $endpoint, $payload, $client);
        } finally {
            $this->limiter->release($slot);
        }
    }

    private function limitGeneration(array &$payload, array $client): bool
    {
        if (isset($payload['options']) && !is_array($payload['options'])) {
            return false;
        }

        $options = $payload['options'] ?? [];
        if (array_is_list($options) && $options !== []) {
            return false;
        }

        $requestedPredict = filter_var($options['num_predict'] ?? $client['max_predict'], FILTER_VALIDATE_INT);
        $requestedContext = filter_var($options['num_ctx'] ?? $client['max_context'], FILTER_VALIDATE_INT);
        if ($requestedPredict === false || $requestedContext === false) {
            return false;
        }

        $options['num_predict'] = min(max(1, $requestedPredict), $client['max_predict']);
        $options['num_ctx'] = min(max(1, $requestedContext), $client['max_context']);
        $payload['options'] = $options;
        $payload['keep_alive'] = getenv('LLAMAGUARD_KEEP_ALIVE') ?: '5m';

        return !isset($payload['stream']) || is_bool($payload['stream']);
    }

    private function proxy(string $method, string $endpoint, ?array $payload, array $client): void
    {
        $filterModels = in_array($endpoint, ['tags', 'ps'], true);
        $buffered = $filterModels;
        $responseBody = '';
        $handle = curl_init($this->ollamaUrl . '/api/' . $endpoint);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use ($buffered): int {
                if ($buffered) {
                    return strlen($line);
                }

                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $status)) {
                    http_response_code((int) $status[1]);
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    header(trim($line), true);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$responseBody, $buffered): int {
                if ($buffered) {
                    $responseBody .= $chunk;
                } else {
                    echo $chunk;
                    flush();
                }

                return strlen($chunk);
            },
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($handle, $options);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($result === false) {
            if (!headers_sent()) {
                $this->jsonResponse(502, ['error' => 'Ollama is unavailable']);
            }
            return;
        }

        if ($buffered) {
            $decoded = json_decode($responseBody, true);
            if ($status >= 200 && $status < 300 && is_array($decoded)) {
                $decoded['models'] = array_values(array_filter(
                    $decoded['models'] ?? [],
                    fn ($model): bool => is_array($model)
                        && in_array($model['name'] ?? $model['model'] ?? null, $this->clients->modelsFor($client), true)
                ));
                $responseBody = json_encode($decoded, JSON_THROW_ON_ERROR);
            }

            http_response_code($status ?: 502);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo $responseBody;
        }
    }

    private function jsonResponse(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }
}