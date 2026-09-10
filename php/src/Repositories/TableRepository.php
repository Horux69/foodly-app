<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Table;
use PDO;

final class TableRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getByCode(string $branchId, string $code): ?Table
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tables WHERE branch_id = :branch_id AND code = :code AND is_active = true'
        );
        $stmt->execute(['branch_id' => $branchId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : Table::fromRow($row);
    }

    /** @return Table[] */
    public function listForBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tables WHERE branch_id = :branch_id ORDER BY code');
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(Table::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Cada mesa con el pedido que tenga encima, si tiene.
     *
     * "Encima" es todo pedido cuyo estado no sea de categoria `completed` ni
     * `cancelled` — por categoria y nunca por codigo (principio 6): cada
     * restaurante bautiza sus estados como quiere, y una consulta que
     * buscara 'pagado' se rompe en el primero que lo llame distinto.
     *
     * El join es LATERAL y con LIMIT 1: una mesa podria tener dos pedidos
     * abiertos —se junta gente, se toma otra cuenta— y el salon muestra el
     * mas antiguo, que es el que lleva mas rato ocupandola.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusForBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id,
                    t.code,
                    t.capacity,
                    t.is_active,
                    t.pos_x,
                    t.pos_y,
                    t.shape,
                    o.id AS order_id,
                    o.order_number,
                    o.total,
                    o.created_at AS occupied_since,
                    s.name AS status_name,
                    s.category AS status_category,
                    o.server_id,
                    sv.name AS server_name,
                    abiertos.cuantos AS open_orders
               FROM tables t
               LEFT JOIN LATERAL (
                    SELECT o.*
                      FROM orders o
                      JOIN order_statuses s ON s.id = o.status_id
                     WHERE o.table_id = t.id
                       AND s.category NOT IN ('completed', 'cancelled')
                  -- El numero desempata: dos pedidos de la misma mesa
                  -- creados en el mismo instante alternarian entre refrescos,
                  -- y el salon se repinta solo.
                  ORDER BY o.created_at, o.order_number
                     LIMIT 1
               ) o ON true
               LEFT JOIN order_statuses s ON s.id = o.status_id
               LEFT JOIN users sv ON sv.id = o.server_id
               LEFT JOIN LATERAL (
                    SELECT count(*) AS cuantos
                      FROM orders o2
                      JOIN order_statuses s2 ON s2.id = o2.status_id
                     WHERE o2.table_id = t.id
                       AND s2.category NOT IN ('completed', 'cancelled')
               ) abiertos ON true
              WHERE t.branch_id = :branch_id
           ORDER BY t.code"
        );
        $stmt->execute(['branch_id' => $branchId]);
        return $stmt->fetchAll();
    }

    /**
     * Coloca una mesa en el plano.
     *
     * Se guarda una a una y no el plano entero: arrastrar una mesa es un
     * cambio, y mandar las veinte cada vez pisaria lo que otra persona
     * acabara de mover desde otra tableta.
     */
    public function place(string $branchId, string $tableId, int $x, int $y, string $shape): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tables SET pos_x = :x, pos_y = :y, shape = :shape
              WHERE branch_id = :branch_id AND id = :id'
        );
        $stmt->execute([
            'x' => $x,
            'y' => $y,
            'shape' => $shape,
            'branch_id' => $branchId,
            'id' => $tableId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function create(string $branchId, string $code, int $capacity): Table
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tables (branch_id, code, capacity) VALUES (:branch_id, :code, :capacity) RETURNING *'
        );
        $stmt->execute(['branch_id' => $branchId, 'code' => $code, 'capacity' => $capacity]);
        return Table::fromRow($stmt->fetch());
    }
}
