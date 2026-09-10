<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\User;
use PDO;

final class UserRepository
{
    private const SELECT = 'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id';

    public function __construct(private readonly PDO $pdo, private readonly RoleRepository $roles)
    {
    }

    /**
     * Los permisos del rol solo se cargan cuando hacen falta (una consulta
     * extra por usuario). El unico que los necesita es el login, para
     * meterlos en el token; listar usuarios muestra el codigo del rol y
     * nada mas, asi que cargarlos ahi seria una consulta por fila y por
     * nada — igual que list_for_tenant en Python, que tampoco los trae.
     */
    private function hydrate(array $row, bool $withPermissions = false): User
    {
        $permissions = [];
        if ($withPermissions) {
            $role = $this->roles->get($row['tenant_id'], $row['role_id']);
            $permissions = $role?->permissionCodes ?? [];
        }
        return User::fromRow($row, $permissions);
    }

    /**
     * Busca un usuario por email dentro del tenant ya fijado en la conexion
     * (RLS). Solo usuarios activos: uno desactivado no debe poder loguear
     * aunque adivine su clave.
     */
    public function getByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.email = :email AND u.is_active = true');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row, withPermissions: true);
    }

    /**
     * Los usuarios activos cuyo rol incluye un permiso dado.
     *
     * Asi es como se responde "quien puede repartir" en un sistema donde los
     * roles son configurables y los permisos no: no se pregunta por un
     * role_code —que cada restaurante bautiza como quiere: 'repartidor',
     * 'domiciliario', 'moto'— sino por la capacidad, que es fija
     * (Core\Permissions).
     *
     * @return User[]
     */
    public function listWithPermission(string $tenantId, string $permissionCode): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' JOIN role_permissions rp ON rp.role_id = r.id
                             JOIN permissions p ON p.id = rp.permission_id
                            WHERE u.tenant_id = :tenant_id
                              AND u.is_active = true
                              AND p.code = :permission
                            ORDER BY u.name'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'permission' => $permissionCode]);
        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function get(string $tenantId, string $userId): ?User
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.tenant_id = :tenant_id AND u.id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    public function getByEmailInTenant(string $tenantId, string $email): ?User
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.tenant_id = :tenant_id AND u.email = :email');
        $stmt->execute(['tenant_id' => $tenantId, 'email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return User[] */
    public function listForTenant(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.tenant_id = :tenant_id ORDER BY u.name');
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function create(
        string $tenantId,
        string $roleId,
        ?string $branchId,
        string $name,
        string $email,
        string $passwordHash,
    ): User {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, role_id, branch_id, name, email, password_hash)
             VALUES (:tenant_id, :role_id, :branch_id, :name, :email, :password_hash)
             RETURNING id'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'branch_id' => $branchId,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        $id = $stmt->fetchColumn();
        return $this->get($tenantId, $id);
    }

    public function setPasswordHash(string $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $passwordHash, 'id' => $userId]);
    }

    public function setActive(string $tenantId, string $userId, bool $isActive): User
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_active = :is_active WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute(['is_active' => Row::pgBool($isActive), 'tenant_id' => $tenantId, 'id' => $userId]);
        return $this->get($tenantId, $userId);
    }
}
