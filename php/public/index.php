<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Api\Router;
use App\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

// Carga .env a $_ENV sin ninguna libreria: unas pocas lineas KEY=VALUE, que
// es todo lo que este proyecto necesita.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

header('Content-Type: application/json; charset=utf-8');

// El frontend vive en web/ y se sirve aparte (mismo patron que
// StaticFiles de FastAPI); este front controller es solo la API.
$prefix = Config::get()->apiV1Prefix;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (str_starts_with($path, $prefix)) {
    $path = substr($path, strlen($prefix));
}
$path = '/' . ltrim($path, '/');

if ($path === '/health') {
    echo json_encode(['status' => 'ok', 'environment' => Config::get()->environment]);
    return;
}

$router = new Router();
require __DIR__ . '/../src/Api/routes.php';

try {
    [$status, $body] = $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
} catch (ApiException $e) {
    http_response_code($e->status);
    echo json_encode(['detail' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    $debug = Config::get()->environment === 'development';
    echo json_encode(['detail' => $debug ? $e->getMessage() : 'Error interno']);
}
