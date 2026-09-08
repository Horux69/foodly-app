<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuracion leida de variables de entorno (.env cargado por el front
 * controller). Equivalente PHP puro de app/core/config.py.
 */
final class Config
{
    private static ?self $instance = null;

    public readonly string $databaseUrl;
    public readonly ?string $appDatabaseUrl;
    public readonly string $secretKey;
    public readonly string $algorithm;
    public readonly int $accessTokenExpireMinutes;
    public readonly string $environment;
    public readonly string $apiV1Prefix;

    private function __construct()
    {
        $this->databaseUrl = self::require('DATABASE_URL');
        $this->appDatabaseUrl = $_ENV['APP_DATABASE_URL'] ?? null;
        $this->secretKey = self::requireSecretKey();
        $this->algorithm = $_ENV['ALGORITHM'] ?? 'HS256';
        $this->accessTokenExpireMinutes = (int) ($_ENV['ACCESS_TOKEN_EXPIRE_MINUTES'] ?? 480);
        $this->environment = $_ENV['ENVIRONMENT'] ?? 'development';
        $this->apiV1Prefix = $_ENV['API_V1_PREFIX'] ?? '/api/v1';
    }

    /**
     * Longitud minima de la clave de firma, en bytes. HS256 firma con
     * HMAC-SHA256 y una clave mas corta que el bloque de la funcion hash
     * reduce la fuerza real de la firma; firebase/php-jwt lo rechaza desde la
     * 7.0 (GHSA-2x45-7fc3-mxwq).
     */
    private const MIN_SECRET_BYTES = 32;

    /**
     * Se valida al arrancar y no al firmar el primer token: asi el fallo sale
     * como un error de configuracion claro en vez de un "Provided key is too
     * short" desde dentro de la libreria, en mitad de un login.
     */
    private static function requireSecretKey(): string
    {
        $value = self::require('SECRET_KEY');
        if (strlen($value) < self::MIN_SECRET_BYTES) {
            throw new \RuntimeException(sprintf(
                'SECRET_KEY es demasiado corta: %d bytes, se necesitan al menos %d. '
                . 'Generar una con: php -r "echo bin2hex(random_bytes(32));"',
                strlen($value),
                self::MIN_SECRET_BYTES,
            ));
        }
        return $value;
    }

    private static function require(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            throw new \RuntimeException("Falta la variable de entorno {$key}");
        }
        return (string) $value;
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }
}
