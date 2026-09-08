<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\TaxRate;
use PDO;

final class TaxRateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $tenantId, string $taxRateId): ?TaxRate
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tax_rates WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $taxRateId]);
        $row = $stmt->fetch();
        return $row === false ? null : TaxRate::fromRow($row);
    }

    public function getDefault(string $tenantId): ?TaxRate
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tax_rates WHERE tenant_id = :tenant_id AND is_default = true');
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : TaxRate::fromRow($row);
    }

    /** @return TaxRate[] */
    public function listForTenant(string $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tax_rates WHERE tenant_id = :tenant_id ORDER BY name');
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(TaxRate::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Hay un indice unico parcial de un solo default por tenant: sin limpiar
     * el anterior, marcar uno nuevo revienta contra la base.
     */
    public function clearDefault(string $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tax_rates SET is_default = false WHERE tenant_id = :tenant_id AND is_default = true'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
    }

    public function create(
        string $tenantId,
        string $name,
        string $rate,
        bool $includedInPrice,
        bool $isDefault,
    ): TaxRate {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tax_rates (tenant_id, name, rate, included_in_price, is_default)
             VALUES (:tenant_id, :name, :rate, :included_in_price, :is_default)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'rate' => $rate,
            'included_in_price' => Row::pgBool($includedInPrice),
            'is_default' => Row::pgBool($isDefault),
        ]);
        return TaxRate::fromRow($stmt->fetch());
    }

    public function setDefault(string $tenantId, string $taxRateId): TaxRate
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tax_rates SET is_default = true WHERE tenant_id = :tenant_id AND id = :id RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $taxRateId]);
        return TaxRate::fromRow($stmt->fetch());
    }
}
