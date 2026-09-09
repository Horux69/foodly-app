<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\RoleRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\AmbiguousLoginError;
use App\Services\AuthError;
use App\Services\AuthService;

/**
 * Entrar cuando el mismo correo esta en dos restaurantes.
 *
 * Antes el login resolvia la empresa con "la primera que tenga ese correo":
 * el segundo restaurante no podia entrar nunca, y sin ningun error que lo
 * explicara. Ahora se prueba la contrasena contra cada candidata y solo se
 * pide el slug si de verdad hace falta — asi el caso ambiguo no se le
 * revela a quien no sabe la contrasena.
 */
final class CorreoRepetidoTest extends IntegrationTestCase
{
    /** Pone el mismo correo en una empresa nueva, con la clave que se le pase. */
    private function usuarioEn(string $tenantId, string $email, string $password): void
    {
        $pdo = Database::admin();
        $roles = new RoleRepository($pdo);
        (new UserRepository($pdo, $roles))->create(
            $tenantId,
            $roles->getByCode($tenantId, 'admin')->id,
            null,
            'Repetido',
            $email,
            Security::hashPassword($password),
        );
    }

    public function testCadaUnoEntraASuRestauranteConSuPropiaClave(): void
    {
        $correo = 'repetido-' . bin2hex(random_bytes(4)) . '@prueba.test';
        [$primera] = $this->nuevaEmpresa();
        [$segunda] = $this->nuevaEmpresa();

        $this->usuarioEn($primera->id, $correo, 'clave de la primera');
        $this->usuarioEn($segunda->id, $correo, 'clave de la segunda');

        $unaCarga = Security::decodeAccessToken(AuthService::login($correo, 'clave de la primera'));
        $otraCarga = Security::decodeAccessToken(AuthService::login($correo, 'clave de la segunda'));

        $this->assertSame($primera->id, $unaCarga['tenant_id']);
        // Esta es la que antes era imposible: la segunda empresa con el mismo
        // correo quedaba fuera para siempre.
        $this->assertSame($segunda->id, $otraCarga['tenant_id']);
    }

    /**
     * Solo si el correo Y la contrasena sirven en las dos se pide el slug.
     * Es el unico caso realmente ambiguo, y solo lo ve quien ya sabe entrar.
     */
    public function testConLaMismaClaveEnLasDosSePideElRestaurante(): void
    {
        $correo = 'repetido-' . bin2hex(random_bytes(4)) . '@prueba.test';
        [$primera] = $this->nuevaEmpresa();
        [$segunda] = $this->nuevaEmpresa();

        $this->usuarioEn($primera->id, $correo, 'la misma clave larga');
        $this->usuarioEn($segunda->id, $correo, 'la misma clave larga');

        $this->expectException(AmbiguousLoginError::class);
        AuthService::login($correo, 'la misma clave larga');
    }

    public function testConElSlugSeEntraDerecho(): void
    {
        $correo = 'repetido-' . bin2hex(random_bytes(4)) . '@prueba.test';
        [$primera] = $this->nuevaEmpresa();
        [$segunda] = $this->nuevaEmpresa();

        $this->usuarioEn($primera->id, $correo, 'la misma clave larga');
        $this->usuarioEn($segunda->id, $correo, 'la misma clave larga');

        $carga = Security::decodeAccessToken(AuthService::login($correo, 'la misma clave larga', $segunda->slug));
        $this->assertSame($segunda->id, $carga['tenant_id']);
    }

    public function testUnSlugQueNoEsElSuyoNoEntra(): void
    {
        $correo = 'repetido-' . bin2hex(random_bytes(4)) . '@prueba.test';
        [$primera] = $this->nuevaEmpresa();
        [$segunda] = $this->nuevaEmpresa();
        $this->usuarioEn($primera->id, $correo, 'la clave larga');

        $this->expectException(AuthError::class);
        AuthService::login($correo, 'la clave larga', $segunda->slug);
    }

    /**
     * Con la contrasena equivocada la respuesta es la de siempre: no se
     * revela en cuantos restaurantes esta ese correo.
     */
    public function testLaClaveEquivocadaNoRevelaLaAmbiguedad(): void
    {
        $correo = 'repetido-' . bin2hex(random_bytes(4)) . '@prueba.test';
        [$primera] = $this->nuevaEmpresa();
        [$segunda] = $this->nuevaEmpresa();
        $this->usuarioEn($primera->id, $correo, 'la misma clave larga');
        $this->usuarioEn($segunda->id, $correo, 'la misma clave larga');

        $this->expectException(AuthError::class);
        $this->expectExceptionMessage('Credenciales invalidas');
        AuthService::login($correo, 'ni idea');
    }

    public function testCadaEmpresaNaceConSuSlug(): void
    {
        [$tenant] = $this->nuevaEmpresa();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $tenant->slug);

        $repo = new TenantRepository(Database::admin());
        $this->assertTrue($repo->slugExists($tenant->slug));
    }
}
