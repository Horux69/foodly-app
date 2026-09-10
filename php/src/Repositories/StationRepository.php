<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use PDO;

/** Las estaciones de preparacion de una empresa y que categorias van a cada una. */
final class StationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array{id: string, name: string, sort_order: int, is_active: bool}> */
    public function listForTenant(string $tenantId, bool $soloActivas = false): array
    {
        $filtro = $soloActivas ? ' AND is_active' : '';
        $stmt = $this->pdo->prepare(
            "SELECT id, name, sort_order, is_active
               FROM stations
              WHERE tenant_id = :tenant_id{$filtro}
           ORDER BY sort_order, name"
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_map(static fn (array $r) => [
            'id' => $r['id'],
            'name' => $r['name'],
            'sort_order' => (int) $r['sort_order'],
            'is_active' => Row::bool($r['is_active']),
        ], $stmt->fetchAll());
    }

    /**
     * A que estacion va cada categoria. Solo las que tienen una asignada.
     *
     * @return array<string, string> id de categoria => id de estacion
     */
    public function categoryRouting(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.station_id
               FROM menu_categories c
               JOIN stations s ON s.id = c.station_id AND s.is_active
              WHERE c.tenant_id = :tenant_id AND c.station_id IS NOT NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapa[$row['id']] = $row['station_id'];
        }
        return $mapa;
    }

    public function create(string $tenantId, string $name, int $sortOrder = 0): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stations (tenant_id, name, sort_order)
             VALUES (:tenant_id, :name, :sort_order)
             RETURNING id, name, sort_order, is_active'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $sortOrder]);
        $row = $stmt->fetch();

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => Row::bool($row['is_active']),
        ];
    }

    public function update(string $tenantId, string $stationId, string $name, bool $isActive): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stations SET name = :name, is_active = :is_active
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'is_active' => Row::pgBool($isActive),
            'tenant_id' => $tenantId,
            'id' => $stationId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Cuantas categorias mandan a esta estacion: lo que hay que mover antes de borrarla. */
    public function categoriesUsing(string $tenantId, string $stationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM menu_categories WHERE tenant_id = :tenant_id AND station_id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $stationId]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(string $tenantId, string $stationId): void
    {
        $this->pdo->prepare('DELETE FROM stations WHERE tenant_id = :tenant_id AND id = :id')
            ->execute(['tenant_id' => $tenantId, 'id' => $stationId]);
    }

    /** Manda una categoria a una estacion, o la deja sin ninguna con null. */
    public function assignCategory(string $tenantId, string $categoryId, ?string $stationId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE menu_categories SET station_id = :station WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute(['station' => $stationId, 'tenant_id' => $tenantId, 'id' => $categoryId]);
        return $stmt->rowCount() > 0;
    }
}
