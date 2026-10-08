<?php

declare(strict_types=1);

namespace Neuralpin\Llamaguard;

final class RequestLimiter
{
    private string $stateDirectory;

    public function __construct(?string $stateDirectory = null)
    {
        $this->stateDirectory = $stateDirectory
            ?? (getenv('LLAMAGUARD_STATE_DIR') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llamaguard');
    }

    /** Returns seconds until retry, or null when the request is allowed. */
    public function consume(string $clientId, int $limit): ?int
    {
        $file = $this->openStateFile('rate-' . hash('sha256', $clientId) . '.json');
        try {
            if (!flock($file, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock rate-limit state.');
            }

            rewind($file);
            $timestamps = json_decode(stream_get_contents($file) ?: '[]', true);
            if (!is_array($timestamps)) {
                $timestamps = [];
            }

            $now = microtime(true);
            $timestamps = array_values(array_filter(
                $timestamps,
                static fn ($timestamp): bool => is_numeric($timestamp) && (float) $timestamp > $now - 60
            ));

            if (count($timestamps) >= $limit) {
                return max(1, (int) ceil((float) $timestamps[0] + 60 - $now));
            }

            $timestamps[] = $now;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($timestamps, JSON_THROW_ON_ERROR));
            fflush($file);

            return null;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /** Returns a held lock for one concurrency slot, or null when all slots are busy. */
    public function acquire(string $clientId, int $maximum): mixed
    {
        $prefix = 'slot-' . hash('sha256', $clientId) . '-';
        for ($slot = 0; $slot < $maximum; $slot++) {
            $file = $this->openStateFile($prefix . $slot . '.lock');
            if (flock($file, LOCK_EX | LOCK_NB)) {
                return $file;
            }

            fclose($file);
        }

        return null;
    }

    public function release(mixed $slot): void
    {
        if (is_resource($slot)) {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    /** @return resource */
    private function openStateFile(string $name)
    {
        if (!is_dir($this->stateDirectory) && !@mkdir($this->stateDirectory, 0700, true) && !is_dir($this->stateDirectory)) {
            throw new \RuntimeException('Unable to create gateway state directory.');
        }

        $path = $this->stateDirectory . DIRECTORY_SEPARATOR . $name;
        $file = @fopen($path, 'c+');
        if ($file === false) {
            throw new \RuntimeException('Unable to open gateway state file.');
        }

        return $file;
    }
}