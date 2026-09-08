<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * El telefono es unico por tenant: es lo que permitira al agente de
     * WhatsApp reconocer al cliente sin pedirle nada.
     */
    public function getOrCreateByPhone(string $tenantId, string $phone, ?string $name = null): Customer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE tenant_id = :tenant_id AND phone = :phone');
        $stmt->execute(['tenant_id' => $tenantId, 'phone' => $phone]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return Customer::fromRow($row);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (tenant_id, phone, name) VALUES (:tenant_id, :phone, :name) RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'phone' => $phone, 'name' => $name]);
        return Customer::fromRow($stmt->fetch());
    }
}
