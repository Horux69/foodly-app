<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Deps;
use App\Api\Request;
use App\Services\AuthError;
use App\Services\AuthService;
use App\Services\PaymentProviders;

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
            [$user, $branch, $tenant, $settings, $branches] = AuthService::getMe($ctx->tenantId, $ctx->userId);
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
            // Entre estas puede elegir el selector de sucursal de la web. La
            // del token sigue siendo la predeterminada; la elegida viaja
            // como ?branch_id= y se valida en Deps::activeBranchId.
            'branches' => array_map(
                static fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code],
                $branches,
            ),
            'tenant_name' => $tenant->name,
            'currency' => $tenant->currency,
            'channels' => $settings->channels,
            // Los metodos de cobro los define PaymentProviders, no la web:
            // el dia que entre una pasarela real, el selector de caja la
            // ofrece sin tocar el frontend.
            'payment_methods' => PaymentProviders::availableMethods(),
            'uses_tables' => $settings->usesTables,
            'asks_tip' => $settings->asksTip,
        ];
    }
}
