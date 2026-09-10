<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A que estacion va cada linea de un pedido.
 *
 * Funcion pura: recibe las lineas y a que estacion pertenece la categoria de
 * cada producto, y devuelve una comanda por estacion. La usan la impresion y
 * el tablero de cocina, y por eso vive aqui y no en ninguna de las dos: una
 * cocina que imprime una cosa y muestra otra es peor que no tener
 * estaciones.
 *
 * Dos decisiones:
 *
 * - **Sin estaciones configuradas, todo va junto.** Es el caso de casi todos
 *   los restaurantes y no tiene que costarles nada: una sola comanda, como
 *   hasta ahora.
 * - **Lo que no tiene estacion no se pierde.** Una categoria sin asignar
 *   —recien creada, o el postre que nadie clasifico— sale en la comanda
 *   general. La alternativa seria que un plato no se preparara porque nadie
 *   configuro algo, y eso se descubre vendiendo.
 */
final class StationRouting
{
    /** Como se llama la comanda de lo que no tiene estacion asignada. */
    public const SIN_ESTACION = 'General';

    private function __construct()
    {
    }

    /**
     * Reparte las lineas en comandas, una por estacion.
     *
     * @param array<int, array{menu_item_id?: string, category_id?: string}> $lineas
     *        cada linea con la categoria de su producto
     * @param array<string, string> $estacionPorCategoria id de categoria => id de estacion
     * @param array<string, string> $nombres id de estacion => como se llama
     * @return array<int, array{station_id: ?string, station_name: string, lines: array<int, mixed>}>
     *         en el orden en que se pasaron los nombres, y la general al final
     */
    public static function split(array $lineas, array $estacionPorCategoria, array $nombres): array
    {
        if ($nombres === []) {
            return $lineas === []
                ? []
                : [['station_id' => null, 'station_name' => self::SIN_ESTACION, 'lines' => $lineas]];
        }

        $porEstacion = [];
        $general = [];
        foreach ($lineas as $linea) {
            $estacion = $estacionPorCategoria[$linea['category_id'] ?? ''] ?? null;
            if ($estacion === null || !isset($nombres[$estacion])) {
                $general[] = $linea;
                continue;
            }
            $porEstacion[$estacion][] = $linea;
        }

        $comandas = [];
        foreach ($nombres as $id => $nombre) {
            if (($porEstacion[$id] ?? []) !== []) {
                $comandas[] = ['station_id' => $id, 'station_name' => $nombre, 'lines' => $porEstacion[$id]];
            }
        }
        if ($general !== []) {
            $comandas[] = ['station_id' => null, 'station_name' => self::SIN_ESTACION, 'lines' => $general];
        }

        return $comandas;
    }
}
