<?php

declare(strict_types=1);

use Neuralpin\Llamaguard\Environment;
use Neuralpin\Llamaguard\OllamaGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Environment::load(dirname(__DIR__) . '/.env');
    (new OllamaGateway())->handle($_SERVER);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Gateway is not configured']);
}