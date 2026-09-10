<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Que un combo se pueda preparar.
 *
 * Un combo es un producto que declara que lleva otros dentro. Las reglas son
 * las que hacen que eso siga siendo imprimible y vendible:
 *
 * - No se contiene a si mismo, ni directa ni indirectamente. Un combo dentro
 *   de otro que lo contiene no se puede expandir para la comanda: la cocina
 *   necesita una lista de platos, no un ciclo.
 * - Un combo lleva al menos dos cosas. Con una sola no es un combo, es el
 *   mismo producto con otro nombre y otro precio — y para eso ya esta el
 *   precio por sucursal.
 */
final class ComboRules
{
    public const MAX_COMPONENTES = 20;

    private function __construct()
    {
    }

    /**
     * @param array<int, array{item_id: string, quantity: int}> $componentes
     * @param array<string, string[]> $componentesDe qué lleva cada producto que ya es combo,
     *                                indexado por id — para detectar ciclos indirectos
     */
    public static function validate(string $comboId, array $componentes, array $componentesDe): void
    {
        if (count($componentes) < 2) {
            throw new ComboError(
                'Un combo lleva al menos dos productos. Con uno solo, lo que hace falta es otro producto '
                . 'o un precio distinto para esa sucursal.'
            );
        }
        if (count($componentes) > self::MAX_COMPONENTES) {
            throw new ComboError('Un combo no puede llevar mas de ' . self::MAX_COMPONENTES . ' productos');
        }

        foreach ($componentes as $componente) {
            if ($componente['quantity'] < 1) {
                throw new ComboError('La cantidad de cada producto del combo tiene que ser al menos 1');
            }
            if ($componente['item_id'] === $comboId) {
                throw new ComboError('Un combo no puede llevarse a si mismo');
            }
        }

        // Ciclo indirecto: A lleva B y B llevaria A. Se recorre hacia abajo
        // desde cada componente con la composicion que ya hay guardada.
        $pendientes = array_column($componentes, 'item_id');
        $vistos = [];
        while ($pendientes !== []) {
            $actual = array_pop($pendientes);
            if (isset($vistos[$actual])) {
                continue;
            }
            $vistos[$actual] = true;

            if ($actual === $comboId) {
                throw new ComboError(
                    'Ese combo terminaria conteniendose a si mismo a traves de otro: la cocina no podria '
                    . 'saber que preparar'
                );
            }
            foreach ($componentesDe[$actual] ?? [] as $hijo) {
                $pendientes[] = $hijo;
            }
        }
    }

    /**
     * Lo que la cocina tiene que preparar por una linea de pedido.
     *
     * Un combo pedido dos veces son dos de cada cosa que lleva. Se multiplica
     * aqui y no en la pantalla para que la comanda impresa, el KDS y
     * cualquier otro cliente digan lo mismo.
     *
     * @param array<int, array{name: string, quantity: int}> $componentes
     * @return array<int, array{name: string, quantity: int}> los mismos, con la cantidad multiplicada
     */
    public static function expand(array $componentes, int $vecesPedido): array
    {
        return array_map(
            static fn (array $c) => [...$c, 'quantity' => $c['quantity'] * $vecesPedido],
            $componentes
        );
    }
}
