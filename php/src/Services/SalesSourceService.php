<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\SourceCommission;
use App\Domain\SourceCommissionError;
use App\Models\SalesSource;
use App\Repositories\SalesSourceRepository;

/**
 * Los origenes de venta del restaurante (F9.4): de donde vienen los pedidos
 * y cuanto se queda cada plataforma.
 */
final class SalesSourceService
{
    /** @return SalesSource[] */
    public static function listChannels(string $tenantId, bool $soloActivos = false): array
    {
        return (new SalesSourceRepository(Database::app()))->listForTenant($tenantId, $soloActivos);
    }

    public static function create(string $tenantId, string $name, float $commissionPercent): SalesSource
    {
        $repo = new SalesSourceRepository(Database::app());
        $name = trim($name);
        if ($name === '') {
            throw new SalesSourceError('El origen necesita un nombre');
        }
        if ($repo->getByName($tenantId, $name) !== null) {
            throw new SalesSourceError("Ya existe un origen llamado '{$name}'");
        }
        try {
            SourceCommission::ensureValid($commissionPercent);
        } catch (SourceCommissionError $e) {
            throw new SalesSourceError($e->getMessage());
        }

        return $repo->create($tenantId, $name, $commissionPercent);
    }

    public static function update(
        string $tenantId,
        string $id,
        string $name,
        float $commissionPercent,
        bool $isActive,
    ): SalesSource {
        $repo = new SalesSourceRepository(Database::app());
        $name = trim($name);
        if ($name === '') {
            throw new SalesSourceError('El origen necesita un nombre');
        }
        $otro = $repo->getByName($tenantId, $name);
        if ($otro !== null && $otro->id !== $id) {
            throw new SalesSourceError("Ya existe un origen llamado '{$name}'");
        }
        try {
            SourceCommission::ensureValid($commissionPercent);
        } catch (SourceCommissionError $e) {
            throw new SalesSourceError($e->getMessage());
        }

        $origen = $repo->update($tenantId, $id, $name, $commissionPercent, $isActive);
        if ($origen === null) {
            throw new SalesSourceError('Ese origen no existe');
        }
        return $origen;
    }

    /**
     * Un origen con ventas encima no se borra, se apaga.
     *
     * Igual que un motivo de descuento usado o un estado con pedidos:
     * borrarlo convertiria las ventas de Rappi del mes pasado en ventas
     * propias, y el reporte de comisiones mentiria hacia arriba. La clave
     * foranea lo bloquearia igual; el mensaje dice cuantas hay.
     */
    public static function delete(string $tenantId, string $id): void
    {
        $repo = new SalesSourceRepository(Database::app());
        $cuantos = $repo->orderCount($tenantId, $id);
        if ($cuantos > 0) {
            throw new SalesSourceError(
                "Este origen tiene {$cuantos} pedido(s): apagalo en vez de borrarlo, o el reporte de comisiones cambiaria"
            );
        }
        if (!$repo->delete($tenantId, $id)) {
            throw new SalesSourceError('Ese origen no existe');
        }
    }

    /**
     * El origen con el que se toma un pedido, y la comision que se congela.
     *
     * @return array{0: ?string, 1: ?float} id del origen y su porcentaje
     */
    public static function resolveForOrder(string $tenantId, ?string $channelId): array
    {
        if ($channelId === null) {
            return [null, null];
        }
        $origen = (new SalesSourceRepository(Database::app()))->get($tenantId, $channelId);
        if ($origen === null) {
            throw new SalesSourceError('Ese origen de venta no existe en este restaurante');
        }
        if (!$origen->isActive) {
            throw new SalesSourceError("El origen '{$origen->name}' esta apagado");
        }
        return [$origen->id, $origen->commissionPercent];
    }
}
