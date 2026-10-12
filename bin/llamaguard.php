<?php

declare(strict_types=1);

use Neuralpin\Llamaguard\ClientRegistry;
use Neuralpin\Llamaguard\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Environment::load(dirname(__DIR__) . '/.env');
    $registry = new ClientRegistry();
    $command = $argv[1] ?? '';

    if ($command === 'init') {
        echo "SQLite settings database and Redis connection are ready.\n";
    } elseif ($command === 'client:add' && count($argv) >= 4) {
        $id = $argv[2];
        $optionNames = [
            'requests-per-minute' => 'requests_per_minute',
            'max-concurrent' => 'max_concurrent',
            'max-predict' => 'max_predict',
            'max-context' => 'max_context',
            'max-request-bytes' => 'max_request_bytes',
        ];
        $models = [];
        $limits = [];
        foreach (array_slice($argv, 3) as $argument) {
            if (!str_starts_with($argument, '--')) {
                $models[] = $argument;
                continue;
            }

            $parts = explode('=', substr($argument, 2), 2);
            if (count($parts) !== 2 || !isset($optionNames[$parts[0]]) || !ctype_digit($parts[1])) {
                throw new InvalidArgumentException('Invalid client limit option.');
            }
            $limits[$optionNames[$parts[0]]] = (int) $parts[1];
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $created = $registry->createClient($id, $token, $models, $limits);
        if ($created) {
            echo "Client created. Store this token securely; it will not be shown again:\n{$token}\n";
        } else {
            echo "Client updated. Added models; the existing bearer token is unchanged.\n";
        }
    } elseif ($command === 'client:remove' && isset($argv[2])) {
        echo $registry->removeClient($argv[2]) ? "Client removed.\n" : "Client not found.\n";
    } elseif ($command === 'client:list') {
        foreach ($registry->allClients() as $client) {
            echo $client['id'] . '  ' . implode(', ', $client['models'])
                . "  rpm={$client['requests_per_minute']} concurrent={$client['max_concurrent']}"
                . " predict={$client['max_predict']} context={$client['max_context']}"
                . " body={$client['max_request_bytes']}" . PHP_EOL;
        }
    } else {
        fwrite(STDERR, "Usage:\n  php bin/llamaguard.php init\n  php bin/llamaguard.php client:add <id> <model> [model ...] [--requests-per-minute=N] [--max-concurrent=N] [--max-predict=N] [--max-context=N] [--max-request-bytes=N]\n  php bin/llamaguard.php client:remove <id>\n  php bin/llamaguard.php client:list\n");
        exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}