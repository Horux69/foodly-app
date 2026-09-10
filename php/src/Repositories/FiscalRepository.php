<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Core\Row;
use PDO;

/** Resoluciones de numeracion y documentos emitidos. */
final class FiscalRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * La resolucion activa de una sucursal, bloqueada hasta el fin de la
     * transaccion.
     *
     * Con FOR UPDATE porque dos cajas emitiendo a la vez leerian el mismo
     * consecutivo y lo repetirian — y un consecutivo repetido no se corrige
     * sin una nota de credito.
     */
    public function activeResolutionForUpdate(string $tenantId, string $branchId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fiscal_resolutions
              WHERE tenant_id = :tenant_id
                AND is_active
                AND (branch_id = :branch_id OR branch_id IS NULL)
           ORDER BY branch_id NULLS LAST
              LIMIT 1
                FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::resolucion($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function listResolutions(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fiscal_resolutions WHERE tenant_id = :tenant_id ORDER BY created_at DESC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(self::resolucion(...), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private static function resolucion(array $row): array
    {
        return [
            'id' => $row['id'],
            'branch_id' => $row['branch_id'],
            'number' => $row['number'],
            'prefix' => $row['prefix'],
            'range_from' => (int) $row['range_from'],
            'range_to' => (int) $row['range_to'],
            'current_number' => (int) $row['current_number'],
            'valid_until' => $row['valid_until'],
            'is_active' => Row::bool($row['is_active']),
        ];
    }

    public function createResolution(
        string $tenantId,
        ?string $branchId,
        string $number,
        string $prefix,
        int $from,
        int $to,
        ?string $validUntil,
    ): array {
        // Una sola activa por sucursal: la anterior se apaga antes, como la
        // marca de estado inicial en F5.2.
        $apagar = $this->pdo->prepare(
            'UPDATE fiscal_resolutions SET is_active = false
              WHERE tenant_id = :tenant_id AND is_active
                AND coalesce(branch_id::text, \'todas\') = coalesce(:branch_id::text, \'todas\')'
        );
        $apagar->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_resolutions
                (tenant_id, branch_id, number, prefix, range_from, range_to, current_number, valid_until)
             VALUES (:tenant_id, :branch_id, :number, :prefix, :from, :to, :current, :valid_until)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'number' => $number,
            'prefix' => $prefix,
            'from' => $from,
            'to' => $to,
            // Nada usado todavia. Va como parametro propio y no como
            // ':from - 1': Postgres deduce el tipo de cada parametro por su
            // uso, y el mismo usado en dos contextos distintos es ambiguo.
            'current' => $from - 1,
            'valid_until' => $validUntil,
        ]);
        return self::resolucion($stmt->fetch());
    }

    public function advanceResolution(string $resolutionId, int $number): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fiscal_resolutions SET current_number = :number WHERE id = :id'
        );
        $stmt->execute(['number' => $number, 'id' => $resolutionId]);
    }

    public function documentForOrder(string $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_documents WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::documento($row);
    }

    /** @param array<string, mixed> $row */
    private static function documento(array $row): array
    {
        return [
            'id' => $row['id'],
            'order_id' => $row['order_id'],
            'prefix' => $row['prefix'],
            'number' => (int) $row['number'],
            'full_number' => $row['prefix'] . $row['number'],
            'status' => $row['status'],
            'external_id' => $row['external_id'],
            'provider' => $row['provider'],
            'total' => Money::fromDecimalString((string) $row['total']),
            'issued_at' => $row['issued_at'],
            'transmitted_at' => $row['transmitted_at'],
        ];
    }

    /** @param array<string, mixed>|null $response */
    public function createDocument(
        string $orderId,
        ?string $resolutionId,
        string $prefix,
        int $number,
        string $status,
        ?string $externalId,
        string $provider,
        ?array $response,
        int $totalCents,
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_documents
                (order_id, resolution_id, prefix, number, status, external_id, provider, response, total,
                 transmitted_at)
             VALUES (:order_id, :resolution_id, :prefix, :number, :status, :external_id, :provider, :response,
                 :total, CASE WHEN :status2 = \'accepted\' THEN now() ELSE NULL END)
             RETURNING *'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'resolution_id' => $resolutionId,
            'prefix' => $prefix,
            'number' => $number,
            'status' => $status,
            'external_id' => $externalId,
            'provider' => $provider,
            'response' => $response === null ? null : json_encode($response, JSON_UNESCAPED_UNICODE),
            'total' => Money::toDecimalString($totalCents),
            'status2' => $status,
        ]);
        return self::documento($stmt->fetch());
    }

    /** Los que quedaron sin transmitir, para reintentarlos. */
    public function pendingTransmission(string $tenantId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.* FROM fiscal_documents d
               JOIN orders o ON o.id = d.order_id
              WHERE o.tenant_id = :tenant_id AND d.status = 'contingency'
           ORDER BY d.issued_at
              LIMIT {$limit}"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(self::documento(...), $stmt->fetchAll());
    }

    /** @param array<string, mixed>|null $response */
    public function markTransmitted(string $documentId, string $status, ?string $externalId, ?array $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fiscal_documents
                SET status = :status,
                    external_id = :external_id,
                    response = :response,
                    transmitted_at = CASE WHEN :status2 = \'accepted\' THEN now() ELSE transmitted_at END
              WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'external_id' => $externalId,
            'response' => $response === null ? null : json_encode($response, JSON_UNESCAPED_UNICODE),
            'id' => $documentId,
            'status2' => $status,
        ]);
    }
}
