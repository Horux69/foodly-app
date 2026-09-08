<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\Order;
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

    public function get(string $tenantId, string $customerId): ?Customer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : Customer::fromRow($row);
    }

    /**
     * Busca por telefono o nombre. El telefono es la llave real: es lo que
     * permitira al futuro agente de WhatsApp reconocer a quien escribe.
     *
     * @return Customer[]
     */
    public function search(string $tenantId, ?string $term, int $limit = 50): array
    {
        $sql = 'SELECT * FROM customers WHERE tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];

        if ($term !== null && $term !== '') {
            // El termino va como parametro preparado, asi que no puede
            // inyectar SQL. Lo que si atraviesa son los comodines de LIKE: un
            // '%' escrito en el buscador lista todos los clientes. Se deja
            // asi, igual que la version Python, porque el resultado nunca sale
            // del tenant y quien busca ya puede listarlos todos con q vacio.
            $sql .= ' AND (lower(phone) LIKE :term OR lower(name) LIKE :term)';
            $params['term'] = '%' . strtolower($term) . '%';
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT :limit');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Customer::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Los ultimos pedidos del cliente, sin sus lineas: la ficha muestra numero,
     * canal y total, y traer los productos de cada uno seria trabajo de mas.
     *
     * @return Order[]
     */
    public function ordersOf(string $tenantId, string $customerId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders
              WHERE tenant_id = :tenant_id AND customer_id = :customer_id
              ORDER BY created_at DESC
              LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('customer_id', $customerId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row) => Order::fromRow($row), $stmt->fetchAll());
    }

    /**
     * Cuanto ha pedido y cuanto ha gastado, contando solo lo completado.
     *
     * Se filtra por `order_statuses.category` y no por el codigo del estado:
     * cada restaurante bautiza sus estados como quiere.
     *
     * @return array{orders: int, spent: string}
     */
    public function statsOf(string $tenantId, string $customerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT count(o.id) AS orders, coalesce(sum(o.total), 0) AS spent
               FROM orders o
               JOIN order_statuses s ON s.id = o.status_id
              WHERE o.tenant_id = :tenant_id
                AND o.customer_id = :customer_id
                AND s.category = 'completed'"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'customer_id' => $customerId]);
        $row = $stmt->fetch();

        return ['orders' => (int) $row['orders'], 'spent' => (string) $row['spent']];
    }

    /**
     * Solo nombre y correo. El telefono es la identidad del cliente y la llave
     * unica por empresa: cambiarlo convertiria a alguien en otra persona en vez
     * de corregir un dato.
     *
     * @param array<string, string|null> $fields
     */
    public function update(string $tenantId, string $customerId, array $fields): Customer
    {
        // Mismo filtro por lista blanca que updateItem: los nombres de columna
        // no pueden ir como parametro preparado, asi que nunca se arma uno con
        // una clave que venga de afuera. 'phone' queda fuera a proposito.
        $columns = array_intersect(array_keys($fields), ['name', 'email']);
        if ($columns === []) {
            // El controlador ya rechaza un PATCH vacio; si se llega aqui es un
            // error de programacion, no una entrada mala del cliente.
            throw new \InvalidArgumentException('No hay columnas que actualizar');
        }

        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", $columns));
        $params = ['tenant_id' => $tenantId, 'id' => $customerId];
        foreach ($columns as $column) {
            $params[$column] = $fields[$column];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE customers SET {$assignments} WHERE tenant_id = :tenant_id AND id = :id RETURNING *"
        );
        $stmt->execute($params);
        return Customer::fromRow($stmt->fetch());
    }
}
