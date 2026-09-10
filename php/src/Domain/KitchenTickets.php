<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * La comanda repartida: por tiempo, y dentro de cada tiempo por estacion.
 *
 * Existe aparte de `StationRouting` porque desde F4.5 la comanda tiene dos
 * dimensiones, y las dos las leen la impresion y el tablero de cocina. Si
 * cada uno agrupara por su lado, tarde o temprano dirian cosas distintas —y
 * una cocina que imprime una cosa y muestra otra es peor que no tener ni
 * estaciones ni tiempos.
 *
 * Dos decisiones:
 *
 * - **Solo sale lo marchado.** Una linea sin `fired_at` es una que el
 *   mesero todavia no mando: ni se imprime ni aparece en el tablero. Si
 *   apareciera, el postre se prepararia con las entradas, que es justo lo
 *   que los tiempos vienen a evitar.
 * - **Sin tiempos configurados no se menciona ninguno.** Es el caso de casi
 *   todos los restaurantes: una sola comanda por estacion, como antes.
 */
final class KitchenTickets
{
    private function __construct()
    {
    }

    /**
     * @param array<int, array<string, mixed>> $lineas con `course` y `fired_at`
     * @param array<string, string> $estacionPorCategoria id de categoria => id de estacion
     * @param array<string, string> $nombresEstacion id de estacion => como se llama
     * @param string[] $tiempos los tiempos del restaurante, en orden
     * @return array<int, array<string, mixed>> comandas con estacion y tiempo
     */
    public static function build(
        array $lineas,
        array $estacionPorCategoria,
        array $nombresEstacion,
        array $tiempos = [],
    ): array {
        $marchadas = array_values(array_filter($lineas, static fn ($l) => ($l['fired_at'] ?? null) !== null));

        $porTiempo = [];
        foreach ($marchadas as $linea) {
            $porTiempo[(int) ($linea['course'] ?? 1)][] = $linea;
        }
        ksort($porTiempo);

        $comandas = [];
        foreach ($porTiempo as $curso => $suyas) {
            foreach (StationRouting::split($suyas, $estacionPorCategoria, $nombresEstacion) as $comanda) {
                $comandas[] = $comanda + [
                    'course' => $curso,
                    // Null y no "Tiempo 1" cuando el restaurante no usa
                    // tiempos: la comanda no tiene por que nombrar algo que
                    // ahi no existe.
                    'course_name' => $tiempos === [] ? null : Courses::label($curso, $tiempos),
                ];
            }
        }

        return $comandas;
    }

    /**
     * Los tiempos que todavia esperan, con su nombre.
     *
     * El tablero los dice: un pedido al que le falta el postre no esta
     * terminado, y sin avisarlo la cocina lo da por despachado.
     *
     * @param array<int, array<string, mixed>> $lineas
     * @param string[] $tiempos
     * @return array<int, array{course: int, name: string}>
     */
    public static function pending(array $lineas, array $tiempos): array
    {
        $cursos = Courses::pendientes(array_map(static fn ($l) => [
            'course' => (int) ($l['course'] ?? 1),
            'fired' => ($l['fired_at'] ?? null) !== null,
        ], $lineas));

        return array_map(
            static fn (int $c) => ['course' => $c, 'name' => Courses::label($c, $tiempos)],
            $cursos,
        );
    }
}
