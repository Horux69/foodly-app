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
    public function create(string $name, string $slug, string $businessType, string $currency, array $settings): Tenant
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenants (name, slug, business_type, currency, settings)
             VALUES (:name, :slug, :business_type, :currency, :settings)
             RETURNING *'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'business_type' => $businessType,
            'currency' => $currency,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
        return Tenant::fromRow($stmt->fetch());
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tenants WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Identidad y configuracion en un solo UPDATE.
     *
     * Van juntas porque cambiar de modelo de negocio cambia los defaults de
     * la configuracion: guardarlas en dos pasos dejaria un instante con el
     * tipo nuevo y los ajustes viejos.
     *
     * @param array<mixed> $settings
     */
    public function update(string $tenantId, string $name, string $businessType, string $currency, array $settings): Tenant
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
                SET name = :name,
                    business_type = :business_type,
                    currency = :currency,
                    settings = :settings,
                    updated_at = now()
              WHERE id = :id
          RETURNING *'
        );
        $stmt->execute([
            'name' => $name,
            'business_type' => $businessType,
            'currency' => $currency,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'id' => $tenantId,
        ]);
        return Tenant::fromRow($stmt->fetch());
    }

    /**
     * Todas las empresas donde ese correo tiene un usuario activo.
     *
     * Unica consulta del sistema que mira a traves de las empresas — corre
     * dentro de la funcion SECURITY DEFINER `auth_tenants_for_email` de la
     * base (ver db/migrations/007_tenant_slug.sql), acotada a devolver ids.
     *
     * Devuelve todas y no la primera a proposito: quien elige es AuthService,
     * despues de comprobar la contrasena. Antes esta consulta devolvia "la
     * primera" y el segundo restaurante con ese correo no podia entrar nunca.
     *
     * @return string[]
     */
    public function tenantsForLogin(string $email): array
    {
        $stmt = $this->pdo->prepare('SELECT auth_tenants_for_email(:email) AS tenant_id');
        $stmt->execute(['email' => $email]);
        return array_values(array_filter(array_column($stmt->fetchAll(), 'tenant_id')));
    }

    /** La empresa de ese slug, si ademas tiene un usuario activo con ese correo. */
    public function tenantForLoginBySlug(string $email, string $slug): ?string
    {
        $stmt = $this->pdo->prepare('SELECT auth_tenant_for_login(:email, :slug) AS tenant_id');
        $stmt->execute(['email' => $email, 'slug' => $slug]);
        $tenantId = $stmt->fetchColumn();
        return $tenantId === false || $tenantId === null ? null : (string) $tenantId;
    }
}
