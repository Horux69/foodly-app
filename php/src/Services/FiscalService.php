<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\FiscalError;
use App\Domain\FiscalNumbering;
use App\Repositories\FiscalRepository;
use App\Repositories\OrderRepository;
use App\Repositories\TenantRepository;

/**
 * Emitir el documento de una venta.
 *
 * Dos decisiones que conviene no deshacer:
 *
 * - **Que el proveedor falle no puede impedir vender.** El documento se
 *   numera con la resolucion, se guarda y se imprime; si la transmision no
 *   sale, queda en contingencia para reintentarla. Un restaurante que no
 *   puede cobrar porque un tercero esta caido devuelve el producto y pierde
 *   al cliente.
 * - **Un pedido tiene un documento y solo uno.** Lo impone la clave unica de
 *   la base, no una comprobacion aqui: emitir dos veces la misma venta no se
 *   corrige sin una nota de credito. Volver a pedirlo devuelve el que ya
 *   existe, como una llave de idempotencia.
 */
final class FiscalService
{
    private static function repo(): FiscalRepository
    {
        return new FiscalRepository(Database::app());
    }

    /** El proveedor que este tenant tiene configurado. Sin nada, el local. */
    private static function providerFor(string $tenantId): FiscalProvider
    {
        $tenant = (new TenantRepository(Database::app()))->get($tenantId);
        $codigo = $tenant?->settings['fiscal_provider'] ?? FiscalProviders::LOCAL;

        return FiscalProviders::get(is_string($codigo) ? $codigo : FiscalProviders::LOCAL);
    }

    /** @return array<string, mixed>|null */
    public static function forOrder(string $tenantId, string $orderId): ?array
    {
        $order = (new OrderRepository(Database::app()))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new FiscalServiceError('Pedido no encontrado');
        }
        return self::repo()->documentForOrder($order->id);
    }

    /** @return array<int, array<string, mixed>> */
    public static function resolutions(string $tenantId): array
    {
        return self::repo()->listResolutions($tenantId);
    }

    public static function createResolution(
        string $tenantId,
        ?string $branchId,
        string $number,
        string $prefix,
        int $from,
        int $to,
        ?string $validUntil,
    ): array {
        if ($to < $from) {
            throw new FiscalServiceError('El rango termina antes de empezar');
        }
        if ($from < 1) {
            throw new FiscalServiceError('El rango empieza en 1 o mas');
        }

        return self::repo()->createResolution($tenantId, $branchId, $number, $prefix, $from, $to, $validUntil);
    }

    /**
     * Emite el documento de un pedido.
     *
     * @return array{0: array<string, mixed>, 1: bool} el documento y si ya existia
     */
    public static function emit(string $tenantId, string $orderId): array
    {
        $pdo = Database::app();
        $orders = new OrderRepository($pdo);
        $repo = self::repo();

        $order = $orders->getById($tenantId, $orderId);
        if ($order === null) {
            throw new FiscalServiceError('Pedido no encontrado');
        }

        $existente = $repo->documentForOrder($order->id);
        if ($existente !== null) {
            return [$existente, true];
        }

        // Se emite sobre una venta saldada: el documento dice cuanto se
        // cobro, y emitirlo antes de cobrar es prometer una cifra que
        // todavia puede cambiar.
        if (!PaymentService::getBalanceForOrder($order)->isSettled) {
            throw new FiscalServiceError('El pedido no esta saldado: primero se cobra y despues se emite');
        }

        $resolucion = $repo->activeResolutionForUpdate($tenantId, $order->branchId);
        if ($resolucion === null) {
            throw new FiscalServiceError(
                'No hay una resolucion de numeracion activa para esta sucursal. '
                . 'Registrala en Administracion antes de emitir.'
            );
        }

        try {
            $numero = FiscalNumbering::next(
                $resolucion['range_from'],
                $resolucion['range_to'],
                $resolucion['current_number'],
                $resolucion['valid_until'],
            );
        } catch (FiscalError $e) {
            throw new FiscalServiceError($e->getMessage());
        }

        $provider = self::providerFor($tenantId);

        // El consecutivo se consume antes de transmitir: si la transmision
        // falla, ese numero ya esta usado por este documento y no se le puede
        // dar a otro. Un numero saltado se explica; uno repetido, no.
        $repo->advanceResolution($resolucion['id'], $numero);

        $documento = [
            'prefix' => $resolucion['prefix'],
            'number' => $numero,
            'order_number' => $order->orderNumber,
            'total_cents' => $order->totalCents,
            'issued_at' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        try {
            $resultado = $provider->transmit($documento);
        } catch (\Throwable $e) {
            // Vender no puede depender de que un tercero este arriba.
            $resultado = new FiscalResult(
                FiscalResult::CONTINGENCY,
                null,
                ['error' => $e->getMessage()],
            );
        }

        $guardado = $repo->createDocument(
            $order->id,
            $resolucion['id'],
            $resolucion['prefix'],
            $numero,
            $resultado->status,
            $resultado->externalId,
            $provider->code(),
            $resultado->response,
            $order->totalCents,
        );

        return [$guardado, false];
    }

    /**
     * Reintenta los que quedaron en contingencia.
     *
     * No renumera: el documento ya tiene su consecutivo y lo conserva. Lo
     * unico que cambia es si la autoridad lo recibio.
     *
     * @return array{enviados: int, pendientes: int}
     */
    public static function retryPending(string $tenantId): array
    {
        $repo = self::repo();
        $provider = self::providerFor($tenantId);

        $enviados = 0;
        $pendientes = $repo->pendingTransmission($tenantId);
        foreach ($pendientes as $documento) {
            try {
                $resultado = $provider->transmit($documento);
            } catch (\Throwable $e) {
                continue;
            }
            if ($resultado->fueAceptado()) {
                $repo->markTransmitted(
                    $documento['id'],
                    $resultado->status,
                    $resultado->externalId,
                    $resultado->response,
                );
                $enviados++;
            }
        }

        return ['enviados' => $enviados, 'pendientes' => count($pendientes) - $enviados];
    }
}
