<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\Role;
use PDO;

final class RoleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return string[] */
    private function permissionCodesForRole(string $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role_id
             ORDER BY p.code'
        );
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function get(string $tenantId, string $roleId): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $roleId]);
        $row = $stmt->fetch();
        return $row === false ? null : Role::fromRow($row, $this->permissionCodesForRole($row['id']));
    }

    public function getByCode(string $tenantId, string $code): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE tenant_id = :tenant_id AND code = :code');
        $stmt->execute(['tenant_id' => $tenantId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : Role::fromRow($row, $this->permissionCodesForRole($row['id']));
    }

    /** @return Role[] */
    public function listForTenant(string $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE tenant_id = :tenant_id ORDER BY name');
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row) => Role::fromRow($row, $this->permissionCodesForRole($row['id'])), $rows);
    }

    public function create(string $tenantId, string $code, string $name, bool $isSystem = false): Role
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (tenant_id, code, name, is_system)
             VALUES (:tenant_id, :code, :name, :is_system)
             RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'is_system' => Row::pgBool($isSystem)]);
        return Role::fromRow($stmt->fetch());
    }

    /** @param string[] $permissionCodes */
    public function setPermissions(string $roleId, array $permissionCodes): void
    {
        $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')
            ->execute(['role_id' => $roleId]);

        if ($permissionCodes === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT :role_id, id FROM permissions WHERE code = :code'
        );
        foreach ($permissionCodes as $code) {
            $stmt->execute(['role_id' => $roleId, 'code' => $code]);

            // El INSERT ... SELECT no falla si el codigo no tiene fila: inserta
            // cero. El permiso quedaria concedido en la pantalla y ausente en
            // la base, que es la peor forma de fallar. Solo pasa si el catalogo
            // de Core\Permissions se adelanto a la migracion que siembra la
            // tabla, y entonces hay que enterarse.
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException(
                    "El permiso '{$code}' esta en el catalogo pero no en la tabla permissions: falta la migracion que lo siembra"
                );
            }
        }
    }
}
