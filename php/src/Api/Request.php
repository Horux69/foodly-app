<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Lectura y validacion minima del cuerpo JSON de la peticion. Equivalente
 * PHP puro de los esquemas Pydantic de app/schemas/*.py: no hay libreria de
 * validacion, cada endpoint pide los campos que necesita con el tipo y los
 * limites que antes declaraba el modelo.
 */
final class Request
{
    /** @return array<string, mixed> */
    public static function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException(422, 'El cuerpo de la peticion debe ser JSON valido');
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, ?int $minLength = null, ?int $maxLength = null): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new ApiException(422, "'{$key}' es obligatorio y debe ser texto");
        }
        $length = strlen($value);
        if ($minLength !== null && $length < $minLength) {
            throw new ApiException(422, "'{$key}' debe tener al menos {$minLength} caracteres");
        }
        if ($maxLength !== null && $length > $maxLength) {
            throw new ApiException(422, "'{$key}' debe tener como maximo {$maxLength} caracteres");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (!is_string($data[$key])) {
            throw new ApiException(422, "'{$key}' debe ser texto");
        }
        return $data[$key];
    }

    /**
     * Parametro de query opcional que debe ser un UUID.
     * Equivalente a los Query(...) tipados de FastAPI en reports.py.
     */
    public static function queryUuid(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !self::isUuid($value)) {
            throw new ApiException(422, "'{$key}' debe ser un UUID valido");
        }
        return $value;
    }

    /** Fecha opcional en formato YYYY-MM-DD. */
    public static function queryDate(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new ApiException(422, "'{$key}' debe ser una fecha en formato YYYY-MM-DD");
        }
        return $value;
    }

    public static function queryInt(string $key, int $default, int $min, int $max): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !preg_match('/^\d+$/', $value)) {
            throw new ApiException(422, "'{$key}' debe ser un numero entero");
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new ApiException(422, "'{$key}' debe estar entre {$min} y {$max}");
        }
        return $number;
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Un id que llega en el body. Sin esta validacion el texto viaja hasta
     * Postgres y vuelve como un 500 con el error de SQL adentro, en vez del
     * 422 que devolvia Pydantic al anotar el campo como uuid.UUID.
     *
     * @param array<string, mixed> $data
     */
    public static function uuid(array $data, string $key): string
    {
        $value = self::string($data, $key);
        if (!self::isUuid($value)) {
            throw new ApiException(422, "'{$key}' debe ser un UUID valido");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalUuid(array $data, string $key): ?string
    {
        $value = self::optionalString($data, $key);
        if ($value !== null && !self::isUuid($value)) {
            throw new ApiException(422, "'{$key}' debe ser un UUID valido");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new ApiException(422, "'{$key}' es obligatorio y debe ser true o false");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, ?int $default = null, int $min = PHP_INT_MIN): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value) || $value < $min) {
            throw new ApiException(422, "'{$key}' debe ser un numero entero" . ($min > PHP_INT_MIN ? " mayor o igual a {$min}" : ''));
        }
        return $value;
    }

    /**
     * Fraccion decimal como string, tal como la espera Money/TaxRate — nunca
     * se convierte a float para no perder precision.
     *
     * @param array<string, mixed> $data
     */
    public static function decimalString(array $data, string $key, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if ($value === null) {
            throw new ApiException(422, "'{$key}' es obligatorio");
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new ApiException(422, "'{$key}' debe ser un numero decimal, p.ej. \"18000.00\"");
        }
        return $value;
    }

    /** @param array<string, mixed> $data @return list<string> */
    public static function stringList(array $data, string $key, array $default = []): array
    {
        $value = $data[$key] ?? $default;
        // count(array_filter(...)) en vez de array_any (PHP >=8.4): el
        // composer.json de este proyecto declara soporte desde PHP 8.1.
        if (!is_array($value) || count(array_filter($value, static fn ($v) => !is_string($v))) > 0) {
            throw new ApiException(422, "'{$key}' debe ser una lista de texto");
        }
        return array_values($value);
    }
}
