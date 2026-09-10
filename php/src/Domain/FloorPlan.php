<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Donde va cada mesa en el plano.
 *
 * Funcion pura, y existe por una razon concreta: **una mesa sin colocar no
 * puede quedar encima de otra**. Todas nacen en 0,0, asi que sin repartirlas
 * el salon de un restaurante que nunca abrio el modo de edicion seria una
 * pila de mesas en una esquina — y quien lo abriera por primera vez tendria
 * que separarlas a mano antes de poder usarlo.
 *
 * Las coordenadas no tienen unidad: la pantalla decide cuanto mide una
 * casilla. Guardar pixeles haria que el mismo plano se viera movido en una
 * tableta y en un portatil.
 */
final class FloorPlan
{
    /** Cuantas mesas caben en una fila antes de bajar a la siguiente. */
    public const COLUMNAS = 6;

    public const FORMAS = ['square', 'round'];

    private function __construct()
    {
    }

    /**
     * Reparte en rejilla las que siguen sin colocar, respetando las que si.
     *
     * @param array<int, array{id: string, pos_x: int, pos_y: int}> $mesas en el orden en que se muestran
     * @return array<int, array{id: string, pos_x: int, pos_y: int}>
     */
    public static function acomodar(array $mesas): array
    {
        $ocupadas = [];
        foreach ($mesas as $mesa) {
            if ($mesa['pos_x'] !== 0 || $mesa['pos_y'] !== 0) {
                $ocupadas["{$mesa['pos_x']},{$mesa['pos_y']}"] = true;
            }
        }

        $siguiente = 0;
        $resultado = [];
        foreach ($mesas as $mesa) {
            if ($mesa['pos_x'] !== 0 || $mesa['pos_y'] !== 0) {
                $resultado[] = $mesa;
                continue;
            }

            // Se busca el primer hueco libre: si alguien ya coloco una mesa
            // en 2,0, la que se acomoda sola no se le pone encima.
            do {
                $x = $siguiente % self::COLUMNAS;
                $y = intdiv($siguiente, self::COLUMNAS);
                $siguiente++;
            } while (isset($ocupadas["{$x},{$y}"]));

            $ocupadas["{$x},{$y}"] = true;
            $resultado[] = [...$mesa, 'pos_x' => $x, 'pos_y' => $y];
        }

        return $resultado;
    }

    /** @throws FloorPlanError */
    public static function validate(int $x, int $y, string $shape): void
    {
        if ($x < 0 || $y < 0) {
            throw new FloorPlanError('Una mesa no puede quedar fuera del plano');
        }
        if (!in_array($shape, self::FORMAS, true)) {
            throw new FloorPlanError("Forma desconocida: '{$shape}'. Validas: " . implode(', ', self::FORMAS));
        }
    }
}
