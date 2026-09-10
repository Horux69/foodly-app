<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use PDO;

/**
 * El catalogo de motivos de descuento de una empresa.
 *
 * Va aparte y no dentro de OrderRepository porque es configuracion, no
 * operacion: lo edita quien administra el restaurante y lo lee quien cobra.
 */
final class DiscountReasonRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array{id: string, name: string, is_active: bool, sort_order: int}> */
    public function listForTenant(string $tenantId, bool $soloActivos = false): array
    {
        $filtro = $soloActivos ? ' AND is_active' : '';
        $stmt = $this->pdo->prepare(
            "SELECT id, name, is_active, sort_order
               FROM discount_reasons
              WHERE tenant_id = :tenant_id{$filtro}
           ORDER BY sort_order, name"
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_map(static fn (array $r) => [
            'id' => $r['id'],
            'name' => $r['name'],
            'is_active' => Row::bool($r['is_active']),
            'sort_order' => (int) $r['sort_order'],
        ], $stmt->fetchAll());
    }

    /** El nombre del motivo, o null si no existe o no es de esta empresa. */
    public function nameOf(string $tenantId, string $reasonId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT name FROM discount_reasons WHERE tenant_id = :tenant_id AND id = :id AND is_active'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $reasonId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    public function create(string $tenantId, string $name, int $sortOrder = 0): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discount_reasons (tenant_id, name, sort_order)
             VALUES (:tenant_id, :name, :sort_order)
             RETURNING id, name, is_active, sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $sortOrder]);
        $row = $stmt->fetch();

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'is_active' => Row::bool($row['is_active']),
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    /** Un motivo ya usado no se borra: se apaga. Borrarlo dejaria descuentos sin explicacion. */
    public function setActive(string $tenantId, string $reasonId, bool $isActive): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discount_reasons SET is_active = :is_active WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            'is_active' => Row::pgBool($isActive),
            'tenant_id' => $tenantId,
            'id' => $reasonId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** El tope de descuento del rol de un usuario, en porcentaje. Null es sin tope. */
    public function maxPercentForUser(string $tenantId, string $userId): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.max_discount_percent
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.tenant_id = :tenant_id AND u.id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $userId]);
        $valor = $stmt->fetchColumn();
        return $valor === false || $valor === null ? null : (float) $valor;
    }
}
