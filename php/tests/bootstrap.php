<?php

declare(strict_types=1);

/**
 * Arranque de la suite.
 *
 * Además del autoload, carga `php/.env` para que las pruebas de integración
 * encuentren TEST_DATABASE_URL. Las de dominio no necesitan nada de esto y
 * siguen corriendo igual si el archivo no existe.
 */

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] ??= trim($value, " \t\n\r\0\x0B\"'");
    }
}
