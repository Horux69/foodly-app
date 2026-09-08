<?php

declare(strict_types=1);

namespace App\Api;

/** Contexto de la peticion: quien pide, de que empresa y con que permisos. */
final class RequestContext
{
    /** @param string[] $permissions */
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly ?string $branchId,
        public readonly string $roleCode,
        public readonly array $permissions,
    ) {
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
