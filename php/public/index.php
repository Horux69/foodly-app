<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Api\Router;
use App\Core\Config;
use App\Core\Database;

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

$prefix = Config::get()->apiV1Prefix;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

/**
 * El frontend se sirve desde el mismo origen que la API: sin CORS de por
 * medio y sin paso de build, igual que el StaticFiles(html=True) que monta
 * app/main.py en "/". Va antes del router para que la API siga mandando en
 * su prefijo.
 */
function serveStaticFile(string $path): bool
{
    $webDir = realpath(dirname(__DIR__, 2) . '/web');
    if ($webDir === false) {
        return false;
    }

    $relative = $path === '/' ? '/index.html' : $path;
    $candidate = realpath($webDir . $relative);

    // realpath ya resolvio ../ y symlinks: si el resultado no cuelga de
    // web/, la peticion se estaba yendo del directorio publico.
    if ($candidate === false || !str_starts_with($candidate, $webDir . DIRECTORY_SEPARATOR)) {
        return false;
    }
    if (is_dir($candidate)) {
        $candidate = $candidate . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($candidate)) {
            return false;
        }
    }
    if (!is_file($candidate)) {
        return false;
    }

    $types = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($candidate));
    readfile($candidate);
    return true;
}

if (!str_starts_with($path, $prefix) && $path !== '/health') {
    if (serveStaticFile($path)) {
        return;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Recurso no encontrado']);
    return;
}

header('Content-Type: application/json; charset=utf-8');

if ($path === '/health') {
    echo json_encode(['status' => 'ok', 'environment' => Config::get()->environment]);
    return;
}

$path = '/' . ltrim(substr($path, strlen($prefix)), '/');

$router = new Router();
require __DIR__ . '/../src/Api/routes.php';

// Una transaccion por request sobre la conexion app(), igual que la Session
// de SQLAlchemy en Python: setTenantContext() la abre en cuanto hay tenant,
// y aqui se cierra siempre, salga como salga la respuesta.
try {
    [$status, $body] = $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    Database::endAppTransaction(success: true);
} catch (ApiException $e) {
    http_response_code($e->status);
    echo json_encode(['detail' => $e->getMessage()]);
    Database::endAppTransaction(success: false);
} catch (\Throwable $e) {
    http_response_code(500);
    // En produccion el cliente solo ve "Error interno"; el detalle va al log
    // del servidor, que si no quedaba sin rastro de la falla en ningun lado.
    error_log(sprintf('[foodly] %s: %s en %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $debug = Config::get()->environment === 'development';
    echo json_encode(['detail' => $debug ? $e->getMessage() : 'Error interno']);
    Database::endAppTransaction(success: false);
}
