<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Deps;
use App\Api\Request;
use App\Services\AuthError;
use App\Services\AuthService;

final class AuthController
{
    public static function login(): array
    {
        $body = Request::json();
        $email = Request::string($body, 'email');
        $password = Request::string($body, 'password');

        try {
            $token = AuthService::login($email, $password);
        } catch (AuthError) {
            throw new \App\Api\ApiException(401, 'Credenciales invalidas');
        }

        return ['access_token' => $token, 'token_type' => 'bearer'];
    }

    public static function me(): array
    {
        $ctx = Deps::getContext();
        try {
            [$user, $branch, $tenant, $settings] = AuthService::getMe($ctx->tenantId, $ctx->userId);
        } catch (AuthError $e) {
            throw new \App\Api\ApiException(401, $e->getMessage());
        }

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roleCode,
            // Los permisos salen del token y no del rol en base: son los que
            // la sesion actual realmente lleva. Si el rol cambio, se aplican
            // al renovar el token, no a mitad de sesion.
            'permissions' => $ctx->permissions,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'tenant_name' => $tenant->name,
            'currency' => $tenant->currency,
            'channels' => $settings->channels,
            'uses_tables' => $settings->usesTables,
            'asks_tip' => $settings->asksTip,
        ];
    }
}
