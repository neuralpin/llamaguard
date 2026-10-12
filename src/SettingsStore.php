<?php

declare(strict_types=1);

namespace Neuralpin\Llamaguard;

use PDO;
use Redis;

final class SettingsStore
{
    private const CACHE_PREFIX = 'llamaguard:settings:v1:';

    private PDO $database;
    private Redis $cache;
    private string $key;
    private int $cacheTtl;

    public function __construct()
    {
        $key = getenv('APP_KEY') ?: '';
        if (!preg_match('/^[a-f0-9]{64}$/i', $key)) {
            throw new \RuntimeException('APP_KEY must be a 64-character hexadecimal key.');
        }
        $decodedKey = hex2bin($key);
        if ($decodedKey === false) {
            throw new \RuntimeException('APP_KEY is invalid.');
        }
        $this->key = $decodedKey;

        $databasePath = getenv('SQLITE_PATH') ?: 'var/llamaguard.sqlite';
        if (!$this->isAbsolutePath($databasePath)) {
            $databasePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $databasePath;
        }

        $databaseDirectory = dirname($databasePath);
        if (!is_dir($databaseDirectory) && !@mkdir($databaseDirectory, 0700, true) && !is_dir($databaseDirectory)) {
            throw new \RuntimeException('Unable to create SQLite database directory.');
        }

        $this->database = new PDO('sqlite:' . $databasePath);
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('PRAGMA secure_delete = ON');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS clients ('
            . 'client_id TEXT PRIMARY KEY, '
            . 'token_hash TEXT NOT NULL UNIQUE, '
            . 'encrypted_settings BLOB NOT NULL, '
            . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
        @chmod($databasePath, 0600);

        if (!class_exists(Redis::class)) {
            throw new \RuntimeException('The PHP Redis extension is required.');
        }

        $this->cache = new Redis();
        $connected = $this->cache->connect(
            getenv('REDIS_HOST') ?: '127.0.0.1',
            (int) (getenv('REDIS_PORT') ?: 6379),
            (float) (getenv('REDIS_TIMEOUT') ?: 1.5)
        );
        if (!$connected) {
            throw new \RuntimeException('Unable to connect to Redis.');
        }

        $password = getenv('REDIS_PASSWORD') ?: '';
        if ($password !== '' && !$this->cache->auth($password)) {
            throw new \RuntimeException('Redis authentication failed.');
        }

        if (!$this->cache->select((int) (getenv('REDIS_DB') ?: 0))) {
            throw new \RuntimeException('Unable to select the Redis database.');
        }

        $this->cacheTtl = max(1, min(3600, (int) (getenv('SETTINGS_CACHE_TTL') ?: 300)));
    }

    /** @return array<string, mixed>|null */
    public function findClientByToken(string $token): ?array
    {
        $tokenHash = hash_hmac('sha256', $token, $this->key);
        $cacheKey = self::CACHE_PREFIX . $tokenHash;
        $cached = $this->cache->get($cacheKey);

        if ($cached === false) {
            $query = $this->database->prepare(
                'SELECT client_id, encrypted_settings FROM clients WHERE token_hash = :token_hash'
            );
            $query->execute(['token_hash' => $tokenHash]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            $clientId = $row['client_id'];
            $encryptedSettings = $row['encrypted_settings'];
            $cacheValue = json_encode([
                'id' => $clientId,
                'settings' => base64_encode($encryptedSettings),
            ], JSON_THROW_ON_ERROR);
            $this->cache->setex($cacheKey, $this->cacheTtl, $cacheValue);
        } else {
            $cacheValue = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($cacheValue) || !is_string($cacheValue['id'] ?? null)
                || !is_string($cacheValue['settings'] ?? null)) {
                $this->cache->del($cacheKey);
                throw new \RuntimeException('Cached client settings are invalid.');
            }

            $clientId = $cacheValue['id'];
            $encryptedSettings = base64_decode($cacheValue['settings'], true);
            if ($encryptedSettings === false) {
                $this->cache->del($cacheKey);
                throw new \RuntimeException('Cached client settings are invalid.');
            }
        }

        $settings = json_decode($this->decrypt($encryptedSettings), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \RuntimeException('Stored client settings are invalid.');
        }

        return ['id' => $clientId] + $settings;
    }

    /** @return array<string, mixed>|null */
    public function findClientById(string $clientId): ?array
    {
        $query = $this->database->prepare(
            'SELECT encrypted_settings FROM clients WHERE client_id = :client_id'
        );
        $query->execute(['client_id' => $clientId]);
        $encryptedSettings = $query->fetchColumn();
        if (!is_string($encryptedSettings)) {
            return null;
        }

        $settings = json_decode($this->decrypt($encryptedSettings), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \RuntimeException('Stored client settings are invalid.');
        }

        return ['id' => $clientId] + $settings;
    }

    public function saveClient(string $clientId, string $token, array $settings): void
    {
        $tokenHash = hash_hmac('sha256', $token, $this->key);
        $encryptedSettings = $this->encrypt(json_encode($settings, JSON_THROW_ON_ERROR));
        $query = $this->database->prepare('SELECT token_hash FROM clients WHERE client_id = :client_id');
        $query->execute(['client_id' => $clientId]);
        $oldTokenHash = $query->fetchColumn();

        $upsert = $this->database->prepare(
            'INSERT INTO clients (client_id, token_hash, encrypted_settings, updated_at) '
            . 'VALUES (:client_id, :token_hash, :settings, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT(client_id) DO UPDATE SET '
            . 'encrypted_settings = excluded.encrypted_settings, updated_at = CURRENT_TIMESTAMP'
        );
        $upsert->bindValue(':client_id', $clientId);
        $upsert->bindValue(':token_hash', $tokenHash);
        $upsert->bindValue(':settings', $encryptedSettings, PDO::PARAM_LOB);
        $upsert->execute();

        $this->cache->del(self::CACHE_PREFIX . (is_string($oldTokenHash) ? $oldTokenHash : $tokenHash));
        $this->cache->del(self::CACHE_PREFIX . $tokenHash);
    }

    public function removeClient(string $clientId): bool
    {
        $query = $this->database->prepare('SELECT token_hash FROM clients WHERE client_id = :client_id');
        $query->execute(['client_id' => $clientId]);
        $tokenHash = $query->fetchColumn();
        if (!is_string($tokenHash)) {
            return false;
        }

        $delete = $this->database->prepare('DELETE FROM clients WHERE client_id = :client_id');
        $delete->execute(['client_id' => $clientId]);
        $this->cache->del(self::CACHE_PREFIX . $tokenHash);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listClients(): array
    {
        $query = $this->database->query('SELECT client_id, encrypted_settings FROM clients ORDER BY client_id');
        $clients = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings = json_decode($this->decrypt($row['encrypted_settings']), true, 512, JSON_THROW_ON_ERROR);
            $clients[] = ['id' => $row['client_id']] + $settings;
        }

        return $clients;
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    private function decrypt(string $ciphertext): string
    {
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($ciphertext) <= $nonceLength) {
            throw new \RuntimeException('Stored client settings cannot be decrypted.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($ciphertext, $nonceLength),
            substr($ciphertext, 0, $nonceLength),
            $this->key
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Stored client settings cannot be decrypted. Check APP_KEY.');
        }

        return $plaintext;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}