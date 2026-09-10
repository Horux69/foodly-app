<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Services\AmbiguousLoginError;
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
        // Opcional: solo hace falta cuando el mismo correo y la misma
        // contrasena sirven en mas de un restaurante.
        $slug = Request::optionalString($body, 'slug');

        try {
            $token = AuthService::login($email, $password, $slug);
        } catch (AmbiguousLoginError $e) {
            // 409 y no 401: las credenciales estan bien, falta decir donde.
            // La pantalla de ingreso lo distingue por el codigo y recien ahi
            // muestra el campo de la empresa.
            throw new \App\Api\ApiException(409, $e->getMessage());
        } catch (AuthError) {
            throw new \App\Api\ApiException(401, 'Credenciales invalidas');
        }

        return ['access_token' => $token, 'token_type' => 'bearer'];
    }

    /**
     * Renueva el token de una sesion viva.
     *
     * La web lo llama sola cuando el token esta por vencer: sin esto, a las
     * ocho horas se cierra la sesion en mitad de un pedido.
     */
    public static function refresh(): array
    {
        $ctx = Deps::getContext();
        try {
            $token = AuthService::refresh($ctx->tenantId, $ctx->userId, $ctx->authTime);
        } catch (AuthError $e) {
            throw new \App\Api\ApiException(401, $e->getMessage());
        }
        return ['access_token' => $token, 'token_type' => 'bearer'];
    }

    /** Cambiar la propia contrasena, sin pasar por un administrador. */
    public static function changePassword(): JsonResponse
    {
        $ctx = Deps::getContext();
        $body = Request::json();

        try {
            AuthService::changePassword(
                $ctx->tenantId,
                $ctx->userId,
                Request::string($body, 'current_password', 1, 200),
                Request::string($body, 'new_password', 1, 200),
            );
        } catch (AuthError $e) {
            throw new \App\Api\ApiException(422, $e->getMessage());
        }

        return new JsonResponse(null, 204);
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
                // Direccion y telefono van aqui porque el ticket del cliente
                // los lleva impresos: sin ellos habria que pedir /branches,
                // que es de administracion y la caja no tiene por que poder.
                static fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'address' => $b->address,
                    'phone' => $b->phone,
                ],
                $branches,
            ),
            'tenant_name' => $tenant->name,
            // El nombre corto con el que se entra cuando el correo se repite:
            // se muestra en Mi cuenta para que se pueda recordar.
            'tenant_slug' => $tenant->slug,
            'currency' => $tenant->currency,
            'channels' => $settings->channels,
            // Los metodos de cobro los define PaymentProviders, no la web:
            // el dia que entre una pasarela real, el selector de caja la
            // ofrece sin tocar el frontend.
            'payment_methods' => PaymentProviders::availableMethods(),
            'uses_tables' => $settings->usesTables,
            'asks_tip' => $settings->asksTip,
            // Cuanto sugerir al cobrar. Es una sugerencia: la propina se
            // puede quitar de un toque.
            'tip_percent' => $settings->tipPercent,
        ];
    }
}
