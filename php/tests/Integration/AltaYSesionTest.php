<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Core\Security;
use App\Services\AuthError;
use App\Services\AuthService;
use App\Services\TenantProvisioning;

/**
 * Dar de alta un restaurante y entrar con el.
 *
 * Es el camino que quedo roto durante toda la fase 5 sin que nadie lo notara:
 * se borro `RoleRepository::listPermissions()` por parecer codigo muerto y
 * `TenantProvisioning` lo seguia usando. La suite estaba en verde porque
 * ninguna prueba llegaba a crear una empresa.
 */
final class AltaYSesionTest extends IntegrationTestCase
{
    public function testUnaEmpresaNuevaQuedaListaParaOperar(): void
    {
        [$tenant, $branch, $user] = $this->nuevaEmpresa();

        $this->assertNotSame('', $tenant->id);
        $this->assertSame($tenant->id, $branch->tenantId);
        $this->assertSame('admin', $user->roleCode);
    }

    /**
     * El rol admin nace con el catalogo entero, que desde F5.5 es el de PHP.
     *
     * Se mira en el token y no en el modelo porque `UserRepository` carga los
     * permisos solo cuando hacen falta —el login— y ese es justamente el
     * unico consumidor que importa.
     */
    public function testElAdminNaceConTodosLosPermisos(): void
    {
        [, , $user, $password] = $this->nuevaEmpresa();

        $carga = Security::decodeAccessToken(AuthService::login($user->email, $password));
        $concedidos = $carga['permissions'];
        sort($concedidos);
        $catalogo = Permissions::codes();
        sort($catalogo);

        $this->assertSame($catalogo, $concedidos);
    }

    /** El preset de estados llega con su inicial y con transiciones. */
    public function testElFlujoDePedidosNaceOperable(): void
    {
        [$tenant] = $this->nuevaEmpresa();
        [$estados, $transiciones, $problemas] = \App\Services\StatusConfigService::getConfiguration($tenant->id);

        $this->assertNotEmpty($estados);
        $this->assertNotEmpty($transiciones);
        $this->assertSame([], $problemas, 'Un restaurante recien creado no puede nacer con el flujo roto');
    }

    public function testEntrarYLeerLaPropiaSesion(): void
    {
        [$tenant, $branch, $user, $password] = $this->nuevaEmpresa();

        $token = AuthService::login($user->email, $password);
        $carga = Security::decodeAccessToken($token);

        $this->assertSame($user->id, $carga['sub']);
        $this->assertSame($tenant->id, $carga['tenant_id']);

        [$leido, $sede] = AuthService::getMe($tenant->id, $user->id);
        $this->assertSame($user->email, $leido->email);
        $this->assertSame($branch->id, $sede->id);
    }

    public function testLaContrasenaEquivocadaNoEntra(): void
    {
        [, , $user] = $this->nuevaEmpresa();

        $this->expectException(AuthError::class);
        AuthService::login($user->email, 'la que no es');
    }

    public function testCambiarLaContrasenaYEntrarConLaNueva(): void
    {
        [$tenant, , $user, $password] = $this->nuevaEmpresa();

        AuthService::changePassword($tenant->id, $user->id, $password, 'otra clave bien larga');

        $this->assertNotSame('', AuthService::login($user->email, 'otra clave bien larga'));

        try {
            AuthService::login($user->email, $password);
            $this->fail('La contrasena vieja deberia haber dejado de servir');
        } catch (AuthError) {
            // esperado
        }
    }

    /** Renovar arrastra el `auth_time`, que es lo que acota la sesion. */
    public function testRenovarConservaElMomentoEnQueSeAutentico(): void
    {
        [$tenant, , $user, $password] = $this->nuevaEmpresa();

        $primero = Security::decodeAccessToken(AuthService::login($user->email, $password));
        $segundo = Security::decodeAccessToken(
            AuthService::refresh($tenant->id, $user->id, (int) $primero['auth_time'])
        );

        $this->assertSame($primero['auth_time'], $segundo['auth_time']);
    }

    public function testUnaSesionDemasiadoViejaNoSeRenueva(): void
    {
        [$tenant, , $user] = $this->nuevaEmpresa();

        $this->expectException(AuthError::class);
        AuthService::refresh($tenant->id, $user->id, time() - 25 * 3600);
    }

    /**
     * La unica consulta que cruza empresas, y esta acotada a devolver un id.
     */
    public function testElLoginEncuentraLaEmpresaDeCadaCorreo(): void
    {
        [$primera, , $usuarioA, $clave] = $this->nuevaEmpresa();
        [$segunda, , $usuarioB] = $this->nuevaEmpresa();

        $this->assertNotSame($primera->id, $segunda->id);

        $carga = Security::decodeAccessToken(AuthService::login($usuarioA->email, $clave));
        $this->assertSame($primera->id, $carga['tenant_id']);
        $this->assertNotSame($usuarioA->email, $usuarioB->email);
    }
}
