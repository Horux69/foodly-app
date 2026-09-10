<?php

declare(strict_types=1);

namespace App\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Cada ruta y su controlador se refieren al mismo parametro.
 *
 * Atrapa la forma exacta en que esta sesion rompio el borrado de un origen
 * de venta: la ruta declaraba `{channel_id:uuid}` y el controlador leia
 * `$params['source_id']`. No hay error de sintaxis, ni de tipos, ni prueba
 * de dominio que lo vea; la peticion llega, el parametro sale nulo y
 * revienta con un TypeError que el cliente ve como un 500 con el nombre de
 * un metodo de PHP adentro.
 *
 * Es la misma idea de `LlamadasTest` y de la prueba de permisos: lo que
 * conecta dos archivos distintos por su nombre se comprueba antes de
 * desplegar, no en la peticion.
 */
final class RutasTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../..';

    /**
     * Las rutas declaradas, con su patron y el metodo que las atiende.
     *
     * @return array<int, array{patron: string, clase: string, metodo: string, archivo: string}>
     */
    private static function rutas(): array
    {
        $rutas = [];
        foreach (glob(self::RAIZ . '/php/src/Api/Routes/*.php') ?: [] as $archivo) {
            $sql = file_get_contents($archivo);
            preg_match_all('/\$router->(?:get|post|put|patch|delete)\((.*?)\);/s', $sql, $llamadas);
            foreach ($llamadas[1] as $argumentos) {
                if (
                    preg_match("/'([^']+)'/", $argumentos, $ruta) !== 1
                    || preg_match('/(\w+Controller)::(\w+)/', $argumentos, $handler) !== 1
                ) {
                    continue;
                }
                $rutas[] = [
                    'patron' => $ruta[1],
                    'clase' => 'App\\Api\\Controllers\\' . $handler[1],
                    'metodo' => $handler[2],
                    'archivo' => basename($archivo),
                ];
            }
        }
        return $rutas;
    }

    /** Los nombres entre llaves de un patron: /orders/{order_id:uuid}/fire => ['order_id'] */
    private static function parametrosDe(string $patron): array
    {
        preg_match_all('/\{([a-zA-Z_]+)(?::[a-z]+)?\}/', $patron, $m);
        return $m[1];
    }

    /** Las claves de $params que lee un metodo del controlador. */
    private static function leidosPor(string $clase, string $metodo): array
    {
        $reflexion = new \ReflectionMethod($clase, $metodo);
        $lineas = file($reflexion->getFileName());
        $cuerpo = implode('', array_slice(
            $lineas,
            $reflexion->getStartLine() - 1,
            $reflexion->getEndLine() - $reflexion->getStartLine() + 1,
        ));
        preg_match_all("/\\\$params\['([a-zA-Z_]+)'\]/", $cuerpo, $m);
        return array_values(array_unique($m[1]));
    }

    public function testHayRutasQueComprobar(): void
    {
        $this->assertGreaterThan(50, count(self::rutas()), 'No se pudieron leer las rutas');
    }

    /** Lo que la ruta declara, el controlador lo lee. */
    public function testCadaParametroDeLaRutaLoLeeSuControlador(): void
    {
        foreach (self::rutas() as $ruta) {
            $declarados = self::parametrosDe($ruta['patron']);
            if ($declarados === []) {
                continue;
            }
            $leidos = self::leidosPor($ruta['clase'], $ruta['metodo']);

            foreach ($declarados as $nombre) {
                $this->assertContains(
                    $nombre,
                    $leidos,
                    "{$ruta['patron']} declara '{$nombre}' y {$ruta['clase']}::{$ruta['metodo']} no lo lee",
                );
            }
        }
    }

    /** Y al reves: lo que el controlador lee, la ruta lo declara. */
    public function testCadaParametroQueElControladorLeeLoDeclaraLaRuta(): void
    {
        foreach (self::rutas() as $ruta) {
            $declarados = self::parametrosDe($ruta['patron']);
            foreach (self::leidosPor($ruta['clase'], $ruta['metodo']) as $nombre) {
                $this->assertContains(
                    $nombre,
                    $declarados,
                    "{$ruta['clase']}::{$ruta['metodo']} lee '{$nombre}', que {$ruta['patron']} no declara",
                );
            }
        }
    }
}
