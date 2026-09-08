<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Motor de estados configurable por tenant.
 *
 * Cada restaurante define sus propios estados y transiciones validas en las
 * tablas order_statuses / order_status_transitions. Esta clase solo valida
 * contra esa configuracion: no conoce ningun estado por nombre.
 */
final class StatusMachine
{
    /** @var array<string, OrderStatus> */
    private array $statuses = [];

    /** @var array<string, StatusTransition> */
    private array $transitions = [];

    /**
     * @param OrderStatus[] $statuses
     * @param StatusTransition[] $transitions
     */
    public function __construct(array $statuses, array $transitions)
    {
        foreach ($statuses as $s) {
            $this->statuses[$s->id] = $s;
        }
        foreach ($transitions as $t) {
            $this->transitions[$this->key($t->fromStatusId, $t->toStatusId)] = $t;
        }
    }

    private function key(string $fromId, string $toId): string
    {
        return "{$fromId}>{$toId}";
    }

    public function initial(): OrderStatus
    {
        foreach ($this->statuses as $s) {
            if ($s->isInitial) {
                return $s;
            }
        }
        throw new TransitionError('El tenant no tiene estado inicial configurado');
    }

    /** @return OrderStatus[] */
    public function allowedFrom(string $statusId): array
    {
        $result = [];
        foreach ($this->transitions as $t) {
            if ($t->fromStatusId === $statusId) {
                $result[] = $this->statuses[$t->toStatusId];
            }
        }
        return $result;
    }

    /** @param string[] $permissions */
    public function validate(string $fromId, string $toId, array $permissions): void
    {
        if ($this->statuses[$fromId]->isFinal) {
            throw new TransitionError('El pedido ya esta en un estado final');
        }

        $transition = $this->transitions[$this->key($fromId, $toId)] ?? null;
        if ($transition === null) {
            throw new TransitionError('Transicion no permitida para este restaurante');
        }

        $needed = $transition->requiredPermission;
        if ($needed !== null && !in_array($needed, $permissions, true)) {
            throw new TransitionError("Falta el permiso: {$needed}");
        }
    }
}
