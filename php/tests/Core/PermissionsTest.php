<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * El catalogo de permisos y la base tienen que decir lo mismo.
 *
 * No se prueba contra un Postgres vivo sino contra el SQL que lo llena: son
 * los archivos que se aplican a cualquier entorno, y asi la prueba corre en
 * la suite de dominio sin base de datos. Lo que atrapa es la deriva —que fue
 * real: `customers.view` y `customers.manage` entraron por la migracion 003 y
 * nadie los agrego al catalogo de PHP, que ademas no se usaba en ninguna
 * parte.
 */
final class PermissionsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../..';

    /** Los codigos que insertan en la tabla `permissions` las semillas y las migraciones. */
    private static function codigosEnSql(): array
    {
        $archivos = array_merge(
            glob(self::RAIZ . '/db/seeds/*.sql') ?: [],
            glob(self::RAIZ . '/db/migrations/*.sql') ?: [],
        );

        $codigos = [];
        foreach ($archivos as $archivo) {
            $sql = file_get_contents($archivo);
            // Cada bloque 'INSERT INTO permissions (code, description) VALUES ...'
            // hasta el punto y coma que lo cierra.
            preg_match_all('/INSERT INTO permissions\s*\([^)]*\)\s*VALUES(.*?);/is', $sql, $bloques);
            foreach ($bloques[1] as $bloque) {
                preg_match_all("/\(\s*'([a-z_]+\.[a-z_]+)'/", $bloque, $encontrados);
                $codigos = array_merge($codigos, $encontrados[1]);
            }
        }

        return array_values(array_unique($codigos));
    }

    public function testElCatalogoYLaBaseTienenLosMismosCodigos(): void
    {
        $enSql = self::codigosEnSql();
        $this->assertNotEmpty($enSql, 'No se encontro ningun INSERT INTO permissions en db/');

        sort($enSql);
        $enCatalogo = Permissions::codes();
        sort($enCatalogo);

        // Solo los codigos: la descripcion de la tabla es documentacion, y la
        // que se le muestra a alguien sale del catalogo.
        $this->assertSame($enCatalogo, $enSql);
    }

    /**
     * Los permisos que exige el codigo existen.
     *
     * Un 'orders.cancell' mal escrito no lo tiene nadie: la pantalla queda
     * cerrada para todos y el 403 repite el codigo equivocado como si fuera
     * cierto. Deps::require lo comprueba en caliente; esto lo comprueba antes
     * de que se despliegue.
     */
    public function testTodoPermisoQueElCodigoExigeEstaEnElCatalogo(): void
    {
        $exigidos = [];
        $directorio = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RAIZ . '/php/src'));
        foreach ($directorio as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            preg_match_all(
                '/Deps::require(?:Any)?\s*\((.*?)\)\s*;/s',
                file_get_contents($archivo->getPathname()),
                $llamadas
            );
            foreach ($llamadas[1] as $argumentos) {
                preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $argumentos, $codigos);
                foreach ($codigos[1] as $codigo) {
                    $exigidos[$codigo] = $archivo->getFilename();
                }
            }
        }

        // No son los 21 del catalogo: los de cocina y descuento no pasan por
        // Deps, los comprueba la maquina de estados contra el
        // `required_permission` que el tenant configuro en sus transiciones.
        $this->assertGreaterThan(10, count($exigidos), 'No se encontraron llamadas a Deps::require');

        foreach ($exigidos as $codigo => $archivo) {
            $this->assertTrue(Permissions::exists($codigo), "{$archivo} exige '{$codigo}', que no esta en el catalogo");
        }
    }

    /**
     * Y al reves: todo permiso del catalogo lo comprueba alguien.
     *
     * Es la prueba que faltaba. `orders.edit` y `orders.discount` estaban en
     * el catalogo desde el principio, sembrados en la base y ofrecidos en el
     * editor de roles —un dueño podia quitarle a su cajero "Aplicar
     * descuentos" y creer que lo habia hecho— y no los comprobaba nadie,
     * porque el endpoint no existia. Un permiso que no se exige en ninguna
     * parte es una promesa falsa, y desde fuera se ve igual que una cumplida.
     *
     * Los tres de la lista no pasan por Deps a proposito: los exige la
     * maquina de estados contra el `required_permission` que cada tenant
     * configuro en sus transiciones (`Domain\StatusMachine::allowedFrom`).
     */
    public function testTodoPermisoDelCatalogoLoComprebaAlguien(): void
    {
        $porTransicion = ['orders.advance_kitchen', 'orders.cancel', 'delivery.complete'];

        $exigidos = [];
        $directorio = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RAIZ . '/php/src'));
        foreach ($directorio as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            preg_match_all(
                '/Deps::require(?:Any)?\s*\((.*?)\)\s*;/s',
                file_get_contents($archivo->getPathname()),
                $llamadas
            );
            foreach ($llamadas[1] as $argumentos) {
                preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $argumentos, $codigos);
                $exigidos = array_merge($exigidos, $codigos[1]);
            }
        }

        $huerfanos = array_values(array_diff(Permissions::codes(), $exigidos, $porTransicion));

        $this->assertSame([], $huerfanos, 'Estos permisos no los comprueba nadie: ' . implode(', ', $huerfanos));
    }
}
