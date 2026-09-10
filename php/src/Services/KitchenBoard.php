<?php

declare(strict_types=1);

namespace App\Services;

/**
 * El tablero de cocina completo.
 *
 * Trae las columnas ademas de los pedidos porque cuales existen depende de
 * como este configurado el restaurante: uno de comida rapida no tiene estados
 * de categoria 'in_transit' y mostrarle esa columna siempre vacia seria ruido.
 */
final class KitchenBoard
{
    /**
     * @param string[] $columns categorias que este tenant si usa, en orden
     * @param KitchenOrder[] $orders lo que esta en curso
     * @param KitchenOrder[] $dispatched lo despachado hace poco, para recuperarlo
     * @param array<int, array{id: string, name: string}> $stations las estaciones activas
     * @param array<string, string> $routing id de categoria del menu => id de estacion
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $orders,
        public readonly array $dispatched,
        public readonly array $stations = [],
        public readonly array $routing = [],
    ) {
    }
}
