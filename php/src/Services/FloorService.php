<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Core\Row;
use App\Repositories\BranchRepository;
use App\Repositories\TableRepository;

/**
 * El salon: que mesa esta ocupada y desde cuando.
 *
 * Para un restaurante de mesa esta es la pantalla principal, y hasta ahora
 * `tables` era una tabla que solo servia para escribir el codigo en el
 * pedido: no habia forma de ver cuales estaban ocupadas.
 *
 * Lo que decide si una mesa esta ocupada es la categoria del estado de su
 * pedido, no su codigo ni una columna `is_occupied` — un dato guardado se
 * desincroniza en cuanto alguien cobra desde otra pantalla.
 */
final class FloorService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tableStatus(string $tenantId, string $branchId): array
    {
        $pdo = Database::app();
        if ((new BranchRepository($pdo))->get($tenantId, $branchId) === null) {
            throw new FloorError('La sucursal no existe para este tenant');
        }

        $ahora = new \DateTimeImmutable('now');

        return array_map(static function (array $row) use ($ahora) {
            $desde = $row['occupied_since'] === null ? null : new \DateTimeImmutable($row['occupied_since']);

            return [
                'id' => $row['id'],
                'code' => $row['code'],
                'capacity' => (int) $row['capacity'],
                'is_active' => Row::bool($row['is_active']),
                'order_id' => $row['order_id'],
                'order_number' => $row['order_number'],
                'total' => $row['total'] === null ? null : Money::toDecimalString(
                    Money::fromDecimalString((string) $row['total'])
                ),
                'status_name' => $row['status_name'],
                'status_category' => $row['status_category'],
                'occupied_since' => $row['occupied_since'],
                // Los minutos los calcula el servidor: el reloj del navegador
                // de una tableta que nadie sincroniza puede ir muy lejos.
                'occupied_minutes' => $desde === null
                    ? null
                    : (int) floor(($ahora->getTimestamp() - $desde->getTimestamp()) / 60),
                // Mas de uno significa que hay otra cuenta en la misma mesa;
                // la pantalla lo dice en vez de esconder una de las dos.
                'open_orders' => (int) $row['open_orders'],
            ];
        }, (new TableRepository($pdo))->statusForBranch($branchId));
    }
}
