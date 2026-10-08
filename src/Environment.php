<?php

declare(strict_types=1);

namespace Neuralpin\Llamaguard;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read .env file.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                throw new \RuntimeException('Invalid .env entry.');
            }

            $name = $matches[1];
            if (getenv($name) !== false) {
                continue;
            }

            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}