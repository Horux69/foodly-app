<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Domain\OrderTracking;
use App\Repositories\BranchRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\TenantRepository;

/**
 * El seguimiento publico de un pedido (F9.2).
 *
 * Es el unico camino de la aplicacion que responde sin sesion, asi que la
 * regla es al reves que en el resto: no se devuelve lo que se tiene a mano
 * sino lo minimo que el cliente ya sabe de su propio pedido. Nada de ids,
 * ni telefonos, ni el nombre de quien lo tomo, ni cuanto se cobro con que
 * metodo.
 *
 * El tenant sale del token —`tracking_tenant_for_token`, la misma salida
 * que usa el login— y de ahi en adelante todo corre bajo RLS: un token no
 * puede leer el pedido de otra empresa ni por error de programacion.
 */
final class TrackingService
{
    /**
     * @return array<string, mixed>
     * @throws TrackingError cuando el token no corresponde a ningun pedido
     */
    public static function track(string $token): array
    {
        // Formato antes que consulta: un token que no tiene la forma no
        // llega a la base, que es la diferencia entre un 404 y treinta mil
        // consultas por segundo de quien esta probando.
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            throw new TrackingError('No encontramos ese pedido');
        }

        $pdo = Database::app();
        $orders = new OrderRepository($pdo);

        $tenantId = $orders->tenantForTrackingToken($token);
        if ($tenantId === null) {
            throw new TrackingError('No encontramos ese pedido');
        }

        Database::setTenantContext($pdo, $tenantId);
        $order = $orders->getByTrackingToken($tenantId, $token);
        if ($order === null || $order->status === null) {
            throw new TrackingError('No encontramos ese pedido');
        }

        $tenant = (new TenantRepository($pdo))->get($tenantId);
        $branch = (new BranchRepository($pdo))->get($tenantId, $order->branchId);
        $delivery = (new DeliveryRepository($pdo))->getForOrder($order->id);
        $esDomicilio = $delivery !== null;

        return [
            'order_number' => $order->orderNumber,
            'placed_at' => $order->createdAt,
            'currency' => $tenant?->currency ?? 'COP',
            // El nombre que el restaurante le puso al estado, con el paso
            // que le corresponde: el nombre es el que usan al telefono, el
            // paso es el que se puede dibujar igual en cualquier flujo.
            'status' => $order->status->name,
            'steps' => OrderTracking::pasos($esDomicilio),
            'step' => OrderTracking::paso($order->status->category, $esDomicilio),
            'cancelled' => OrderTracking::anulado($order->status->category),
            'is_delivery' => $esDomicilio,
            'address' => $delivery?->address,
            'promised_at' => $delivery?->estimatedTime,
            'dispatched_at' => $delivery?->dispatchedAt,
            'delivered_at' => $delivery?->deliveredAt,
            // Solo cuando ya salio: antes de eso no significa nada, y el
            // nombre de un empleado no se reparte sin motivo.
            'courier_name' => $delivery?->dispatchedAt === null
                ? null
                : OrderTracking::nombreCorto($delivery?->courierName),
            'items' => array_map(static fn ($i) => [
                'quantity' => $i->quantity,
                'name' => $i->nameSnapshot,
            ], $order->items),
            'total' => Money::toDecimalString($order->totalCents),
            'restaurant' => [
                'name' => $tenant?->name,
                'branch' => $branch?->name,
                // Para llamar si algo pasa. Es el telefono del restaurante,
                // que ya esta en su factura y en su puerta.
                'phone' => $branch?->phone,
            ],
        ];
    }
}
