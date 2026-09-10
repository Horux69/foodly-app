<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Core\Row;
use App\Domain\FloorPlan;
use App\Domain\FloorPlanError;
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

        $filas = (new TableRepository($pdo))->statusForBranch($branchId);

        // Las que nadie colocó se reparten en rejilla: todas nacen en 0,0 y
        // sin esto el salón sería una pila de mesas en una esquina.
        $colocadas = [];
        foreach (FloorPlan::acomodar(array_map(static fn (array $r) => [
            'id' => $r['id'],
            'pos_x' => (int) $r['pos_x'],
            'pos_y' => (int) $r['pos_y'],
        ], $filas)) as $mesa) {
            $colocadas[$mesa['id']] = $mesa;
        }

        $ahora = new \DateTimeImmutable('now');

        return array_map(static function (array $row) use ($ahora, $colocadas) {
            $desde = $row['occupied_since'] === null ? null : new \DateTimeImmutable($row['occupied_since']);

            return [
                'id' => $row['id'],
                'code' => $row['code'],
                'capacity' => (int) $row['capacity'],
                'is_active' => Row::bool($row['is_active']),
                'pos_x' => $colocadas[$row['id']]['pos_x'],
                'pos_y' => $colocadas[$row['id']]['pos_y'],
                'shape' => $row['shape'],
                'order_id' => $row['order_id'],
                'order_number' => $row['order_number'],
                'total' => $row['total'] === null ? null : Money::toDecimalString(
                    Money::fromDecimalString((string) $row['total'])
                ),
                'status_name' => $row['status_name'],
                // Quien atiende la mesa: es lo que hace posible el "mis
                // mesas" de una tableta que usan todos.
                'server_id' => $row['server_id'],
                'server_name' => $row['server_name'],
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
        }, $filas);
    }

    /**
     * Mueve una mesa en el plano.
     *
     * Una a una: arrastrar una mesa es un cambio, y mandar el plano entero
     * pisaría lo que otra persona acabara de mover desde otra tableta.
     */
    public static function place(
        string $tenantId,
        string $branchId,
        string $tableId,
        int $x,
        int $y,
        string $shape,
    ): void {
        $pdo = Database::app();
        if ((new BranchRepository($pdo))->get($tenantId, $branchId) === null) {
            throw new FloorError('La sucursal no existe para este tenant');
        }

        try {
            FloorPlan::validate($x, $y, $shape);
        } catch (FloorPlanError $e) {
            throw new FloorError($e->getMessage());
        }

        if (!(new TableRepository($pdo))->place($branchId, $tableId, $x, $y, $shape)) {
            throw new FloorError('Esa mesa no existe en esta sucursal');
        }
    }
}
