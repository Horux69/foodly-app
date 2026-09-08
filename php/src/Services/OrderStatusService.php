<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\StatusMachine;
use App\Domain\TransitionError;
use App\Models\Order;
use App\Models\OrderStatusRow;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;

/**
 * Avance de estado de un pedido.
 *
 * Valida contra la maquina de estados que cada tenant configuro (nunca contra
 * codigos hardcodeados) y exige el permiso que declara cada transicion. Cocina
 * y caja usan este mismo camino: lo unico que cambia es el permiso que pide la
 * transicion configurada.
 */
final class OrderStatusService
{
    /** @return array{0: StatusMachine, 1: array<string, OrderStatusRow>} */
    public static function buildMachine(string $tenantId): array
    {
        $repo = new OrderStatusRepository(Database::app());
        $statuses = $repo->listStatuses($tenantId);

        $machine = new StatusMachine(
            array_map(static fn (OrderStatusRow $s) => $s->toDomain(), $statuses),
            $repo->listTransitions($tenantId),
        );

        $byId = [];
        foreach ($statuses as $status) {
            $byId[$status->id] = $status;
        }
        return [$machine, $byId];
    }

    /** @return OrderStatusRow[] */
    public static function allowedNextStatuses(string $tenantId, Order $order): array
    {
        [$machine, $byId] = self::buildMachine($tenantId);
        return array_map(
            static fn ($s) => $byId[$s->id],
            $machine->allowedFrom($order->statusId),
        );
    }

    /** @param string[] $permissions */
    public static function advanceStatus(
        string $tenantId,
        string $orderId,
        string $toStatusId,
        array $permissions,
        ?string $changedBy,
        ?string $note = null,
    ): Order {
        $orders = new OrderRepository(Database::app());

        // Con bloqueo: dos cajeros que avancen el mismo pedido a la vez ya no
        // se pisan, el segundo espera y valida contra el estado que quedo.
        $order = $orders->getByIdForUpdate($tenantId, $orderId);
        if ($order === null) {
            throw new OrderStatusError('Pedido no encontrado');
        }

        [$machine, $byId] = self::buildMachine($tenantId);
        $target = $byId[$toStatusId] ?? null;
        if ($target === null) {
            throw new OrderStatusError('El estado no existe para este tenant');
        }

        try {
            $machine->validate($order->statusId, $toStatusId, $permissions);
        } catch (TransitionError $e) {
            throw new OrderStatusError($e->getMessage());
        }

        // Regla de caja: un pedido no se da por completado sin estar saldado.
        // Se apoya en la categoria, no en el nombre del estado, y deja fuera
        // 'cancelled' (tambien final) porque un pedido sin pagar si se cancela.
        if ($target->category === 'completed') {
            $balance = PaymentService::getBalanceForOrder($order);
            if (!$balance->isSettled) {
                $pending = \App\Core\Money::toDecimalString($balance->pendingCents);
                throw new OrderStatusError("El pedido no esta saldado: faltan {$pending}");
            }
        }

        $orders->setStatus($order->id, $target->id);
        $orders->addStatusHistory($order->id, $target->id, $changedBy, $note);

        // Modulo 6: si el pedido es un domicilio, aqui queda la hora de salida
        // o de entrega. Va despues del cambio de estado y no antes para que un
        // rechazo de la maquina de estados no deje una hora sellada de un
        // avance que nunca ocurrio.
        DeliveryService::stampByCategory($order->id, $target->category);

        return $orders->getById($tenantId, $order->id);
    }
}
