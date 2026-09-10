<?php

declare(strict_types=1);

namespace App\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Que los metodos que el codigo llama existan.
 *
 * No hay pruebas de integracion en PHP: el dominio tiene suite y el resto se
 * valida a mano. El agujero que eso deja se cobro dos veces en la fase 5 —
 * un `OrderStatusService::buildMachine()` que dejo de existir al pisar el
 * archivo con una clase nueva del mismo nombre, y un
 * `RoleRepository::listPermissions()` que se borro por parecer codigo muerto
 * mientras TenantProvisioning lo seguia usando—. Las dos veces la suite quedo
 * verde y el fallo aparecio corriendo la aplicacion: la primera con un 500 en
 * el KDS, la segunda con el alta de restaurantes rota.
 *
 * Esto no reemplaza a las pruebas de integracion, pero atrapa esa forma
 * concreta de romper, que es barata de comprobar: se recorre php/src, se
 * resuelven las llamadas a clases de App\ y se pregunta por reflexion si el
 * metodo esta.
 */
final class LlamadasTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../src';

    /** @return string[] */
    private static function archivos(): array
    {
        $encontrados = [];
        $directorio = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RAIZ));
        foreach ($directorio as $archivo) {
            if ($archivo->getExtension() === 'php') {
                $encontrados[] = $archivo->getPathname();
            }
        }
        sort($encontrados);
        return $encontrados;
    }

    /**
     * Nombre corto -> clase completa, segun los `use` del archivo y su propio
     * namespace.
     *
     * @return array<string, string>
     */
    private static function clasesVisibles(string $codigo): array
    {
        $mapa = [];
        preg_match_all('/^use\s+(App\\\\[\w\\\\]+);/m', $codigo, $usos);
        foreach ($usos[1] as $completa) {
            $mapa[substr(strrchr($completa, '\\'), 1)] = $completa;
        }

        // Las del mismo namespace no llevan `use`.
        if (preg_match('/^namespace\s+([\w\\\\]+);/m', $codigo, $ns) === 1) {
            $carpeta = self::RAIZ . '/' . str_replace(['App\\', '\\'], ['', '/'], $ns[1]);
            foreach (glob($carpeta . '/*.php') ?: [] as $vecino) {
                $nombre = basename($vecino, '.php');
                $mapa[$nombre] ??= $ns[1] . '\\' . $nombre;
            }
        }
        return $mapa;
    }

    public function testLasLlamadasEstaticasApuntanAMetodosQueExisten(): void
    {
        $revisadas = 0;

        foreach (self::archivos() as $archivo) {
            $codigo = file_get_contents($archivo);
            $visibles = self::clasesVisibles($codigo);

            preg_match_all('/\b([A-Z]\w+)::(\w+)\s*\(/', $codigo, $llamadas, PREG_SET_ORDER);
            foreach ($llamadas as [, $corto, $metodo]) {
                $clase = $visibles[$corto] ?? null;
                if ($clase === null || !class_exists($clase)) {
                    continue; // clase de fuera de App\, o un nombre que no es una clase
                }
                $revisadas++;
                $this->assertTrue(
                    method_exists($clase, $metodo),
                    basename($archivo) . " llama a {$corto}::{$metodo}(), que no existe en {$clase}"
                );
            }
        }

        $this->assertGreaterThan(100, $revisadas, 'No se reviso casi nada: el analisis dejo de encontrar llamadas');
    }

    /**
     * Lo mismo para las variables de tipo conocido: `$repo = new Algo(...)`
     * y los parametros declarados `Algo $repo`, seguidos de `$repo->metodo()`.
     *
     * Los parametros cuentan porque el codigo pasa repositorios de un metodo
     * a otro —`guardarLinea(OrderRepository $orders, ...)`— y sin mirarlos el
     * analisis se queda ciego justo donde mas se encadenan llamadas.
     *
     * Solo se miran las variables con un unico tipo en el archivo: con dos no
     * se puede saber cual esta viva en cada llamada, y una prueba que adivina
     * es peor que ninguna.
     */
    public function testLasLlamadasSobreVariablesInstanciadasTambien(): void
    {
        $revisadas = 0;

        foreach (self::archivos() as $archivo) {
            $codigo = file_get_contents($archivo);
            $visibles = self::clasesVisibles($codigo);

            preg_match_all('/\$(\w+)\s*=\s*new\s+([A-Z]\w+)\s*\(/', $codigo, $altas, PREG_SET_ORDER);
            // `Algo $var` en una firma: tipo declarado, sin `new` de por medio.
            preg_match_all('/(?:[(,]|^\s*)\s*(?:readonly\s+)?\??([A-Z]\w+)\s+\$(\w+)\s*[,)=]/m', $codigo, $params, PREG_SET_ORDER);

            $tipos = [];
            $vistos = [];
            foreach ($altas as [, $variable, $corto]) {
                $tipos[$variable] = $corto;
                $vistos[$variable][$corto] = true;
            }
            foreach ($params as [, $corto, $variable]) {
                $tipos[$variable] ??= $corto;
                $vistos[$variable][$corto] = true;
            }

            foreach ($tipos as $variable => $corto) {
                if (count($vistos[$variable]) > 1) {
                    continue;
                }
                $clase = $visibles[$corto] ?? null;
                if ($clase === null || !class_exists($clase)) {
                    continue;
                }

                preg_match_all('/\$' . preg_quote((string) $variable, '/') . '->(\w+)\s*\(/', $codigo, $usos);
                foreach (array_unique($usos[1]) as $metodo) {
                    $revisadas++;
                    $this->assertTrue(
                        method_exists($clase, $metodo),
                        basename($archivo) . " llama a \${$variable}->{$metodo}(), que no existe en {$clase}"
                    );
                }
            }
        }

        $this->assertGreaterThan(30, $revisadas, 'No se reviso casi nada: el analisis dejo de encontrar llamadas');
    }
}
