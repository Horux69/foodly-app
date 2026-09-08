<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Tenant;
use PDO;

final class TenantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $tenantId): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id');
        $stmt->execute(['id' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : Tenant::fromRow($row);
    }

    /** @param array<mixed> $settings */
    public function create(string $name, string $businessType, string $currency, array $settings): Tenant
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenants (name, business_type, currency, settings)
             VALUES (:name, :business_type, :currency, :settings)
             RETURNING *'
        );
        $stmt->execute([
            'name' => $name,
            'business_type' => $businessType,
            'currency' => $currency,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
        return Tenant::fromRow($stmt->fetch());
    }

    /** @param array<mixed> $settings */
    public function updateSettings(string $tenantId, array $settings): Tenant
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants SET settings = :settings, updated_at = now() WHERE id = :id RETURNING *'
        );
        $stmt->execute([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'id' => $tenantId,
        ]);
        return Tenant::fromRow($stmt->fetch());
    }

    /**
     * Resuelve a que empresa pertenece un email, antes de tener tenant fijado.
     * Unica consulta del sistema que mira a traves de las empresas — corre
     * dentro de la funcion SECURITY DEFINER auth_tenant_for_email de la base
     * (ver db/migrations/002_rls_hardening.sql), acotada a devolver solo el
     * tenant_id.
     */
    public function findTenantForLogin(string $email): ?string
    {
        $stmt = $this->pdo->prepare('SELECT auth_tenant_for_email(:email) AS tenant_id');
        $stmt->execute(['email' => $email]);
        $tenantId = $stmt->fetchColumn();
        return $tenantId === false || $tenantId === null ? null : (string) $tenantId;
    }
}
