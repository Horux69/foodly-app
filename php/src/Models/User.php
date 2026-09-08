<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class User
{
    /** @param string[] $permissionCodes permisos del rol del usuario */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly ?string $branchId,
        public readonly string $roleId,
        public readonly string $roleCode,
        public readonly array $permissionCodes,
        public readonly string $name,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $row fila de users con role_code y (opcional) permission_codes ya resueltos
     * @param string[] $permissionCodes
     */
    public static function fromRow(array $row, array $permissionCodes = []): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            branchId: $row['branch_id'],
            roleId: $row['role_id'],
            roleCode: $row['role_code'],
            permissionCodes: $permissionCodes,
            name: $row['name'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
