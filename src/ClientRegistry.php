<?php

declare(strict_types=1);

namespace Neuralpin\Llamaguard;

final class ClientRegistry
{
    private const LIMITS = [
        'requests_per_minute' => [30, 600],
        'max_concurrent' => [1, 16],
        'max_predict' => [1024, 32768],
        'max_context' => [8192, 131072],
        'max_request_bytes' => [1048576, 16777216],
    ];

    private SettingsStore $store;

    public function __construct(?SettingsStore $store = null)
    {
        $this->store = $store ?? new SettingsStore();
    }

    /** @return array<string, mixed>|null */
    public function authenticate(array $server): ?array
    {
        $authorization = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($authorization) || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        return $this->store->findClientByToken(trim($matches[1]));
    }

    /** @return list<string> */
    public function modelsFor(array $client): array
    {
        return $client['models'];
    }

    /** @param list<string> $models */
    public function createClient(string $id, string $token, array $models, array $limits = []): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) || strlen($token) < 32 || $models === []) {
            throw new \InvalidArgumentException('Client ID, token, or model allowlist is invalid.');
        }
        if (array_diff(array_keys($limits), array_keys(self::LIMITS)) !== []) {
            throw new \InvalidArgumentException('A client limit name is not supported.');
        }

        foreach ($models as $model) {
            if (!is_string($model) || $model === '' || strlen($model) > 200) {
                throw new \InvalidArgumentException('The model allowlist contains an invalid model name.');
            }
        }

        $settings = ['models' => array_values(array_unique($models))];
        foreach (self::LIMITS as $name => [$default, $maximum]) {
            $value = $limits[$name] ?? $default;
            if (!is_int($value) || $value < 1) {
                throw new \InvalidArgumentException("Client limit {$name} must be a positive integer.");
            }
            $settings[$name] = min($value, $maximum);
        }

        $this->store->saveClient($id, $token, $settings);
    }

    public function removeClient(string $id): bool
    {
        return $this->store->removeClient($id);
    }

    /** @return list<array<string, mixed>> */
    public function allClients(): array
    {
        return $this->store->listClients();
    }
}