<?php

declare(strict_types=1);

namespace App\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Hash de contrasenas con la funcion nativa de PHP (bcrypt por defecto,
 * equivalente a passlib+bcrypt en la version Python) y JWT con
 * firebase/php-jwt: es la unica dependencia via Composer que este proyecto
 * usa, precisamente porque decodificar un JWT a mano es el tipo de cosa
 * criptografica que no vale la pena reinventar.
 */
final class Security
{
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function verifyPassword(string $plain, string $hashed): bool
    {
        return password_verify($plain, $hashed);
    }

    /**
     * El tenant_id viaja SIEMPRE dentro del token. Nunca se acepta desde el
     * body ni desde query params: ese es el vector principal de fuga de
     * datos entre empresas.
     *
     * @param string[] $permissions
     */
    public static function createAccessToken(
        string $userId,
        string $tenantId,
        ?string $branchId,
        string $roleCode,
        array $permissions,
        ?int $authTime = null,
    ): string {
        $config = Config::get();
        $now = time();
        $payload = [
            'sub' => $userId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'role' => $roleCode,
            'permissions' => $permissions,
            'iat' => $now,
            // Cuando la persona escribio su contrasena. Se arrastra de un
            // token al siguiente al renovar, porque es lo que acota cuanto
            // puede vivir una sesion (ver Domain\SessionRenewal).
            'auth_time' => $authTime ?? $now,
            'exp' => $now + $config->accessTokenExpireMinutes * 60,
        ];
        return JWT::encode($payload, $config->secretKey, $config->algorithm);
    }

    /** @return array{sub:string, tenant_id:string, branch_id:?string, role:string, permissions:string[], auth_time?:int} */
    public static function decodeAccessToken(string $token): array
    {
        $config = Config::get();
        $decoded = JWT::decode($token, new Key($config->secretKey, $config->algorithm));
        return (array) json_decode(json_encode($decoded), true);
    }
}
