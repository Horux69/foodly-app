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
        $this->secretKey = self::require('SECRET_KEY');
        $this->algorithm = $_ENV['ALGORITHM'] ?? 'HS256';
        $this->accessTokenExpireMinutes = (int) ($_ENV['ACCESS_TOKEN_EXPIRE_MINUTES'] ?? 480);
        $this->environment = $_ENV['ENVIRONMENT'] ?? 'development';
        $this->apiV1Prefix = $_ENV['API_V1_PREFIX'] ?? '/api/v1';
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
