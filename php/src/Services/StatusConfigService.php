<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Permissions;
use App\Domain\OrderStatus;
use App\Domain\StatusConfigError;
use App\Domain\StatusMachineRules;
use App\Models\OrderStatusRow;
use App\Repositories\OrderStatusRepository;

/**
 * Editar los estados de pedido y sus transiciones.
 *
 * Aparte de OrderStatusService, que avanza el estado de un pedido concreto:
 * aquel opera con el flujo, este lo define.
 *
 * Cada operacion termina releyendo la configuracion entera y validandola: si
 * queda inoperable, la excepcion aborta la transaccion de la peticion y no se
 * guarda nada. Se valida el resultado y no la edicion porque lo que deja un
 * estado sin salida no suele ser tocarlo a el, sino borrar el unico al que
 * llevaba.
 */
final class StatusConfigService
{
    private static function pdo(): \PDO
    {
        return Database::app();
    }

    /**
     * @return array{0: OrderStatusRow[], 1: array<int, array{from: string, to: string, permission: ?string}>, 2: string[], 3: string[]}
     *         estados, transiciones, problemas y avisos
     */
    public static function getConfiguration(string $tenantId): array
    {
        $repo = new OrderStatusRepository(self::pdo());
        $statuses = $repo->listStatuses($tenantId);
        $transitions = $repo->listTransitions($tenantId);

        $planas = array_map(
            static fn ($t) => ['from' => $t->fromStatusId, 'to' => $t->toStatusId, 'permission' => $t->requiredPermission],
            $transitions
        );
        $dominio = self::asDomain($statuses);

        // Los problemas viajan aparte de los avisos: una configuracion rota se
        // puede seguir editando (para arreglarla), asi que hay que verla.
        return [
            $statuses,
            $planas,
            StatusMachineRules::problems($dominio, $transitions),
            StatusMachineRules::warnings($dominio, $transitions),
        ];
    }

    public static function createStatus(
        string $tenantId,
        string $name,
        string $category,
        ?string $color,
        int $sortOrder,
        bool $isFinal,
    ): OrderStatusRow {
        $repo = new OrderStatusRepository(self::pdo());
        self::validarCategoria($category);
        self::validarColor($color);
        $antes = self::problemas($tenantId, $repo);

        $code = self::codigoLibre($repo, $tenantId, $name);
        $status = $repo->createStatus($tenantId, $code, trim($name), $category, $color, $sortOrder, $isFinal);

        // Un estado recien creado no tiene salidas, asi que solo pasa si
        // nace final: es mejor negarse aqui que dejar el flujo roto para el
        // turno siguiente.
        self::ensureNotWorse($tenantId, $repo, $antes);
        return $status;
    }

    public static function updateStatus(
        string $tenantId,
        string $statusId,
        string $name,
        string $category,
        ?string $color,
        int $sortOrder,
        bool $isFinal,
    ): OrderStatusRow {
        $repo = new OrderStatusRepository(self::pdo());
        $actual = self::estado($repo, $tenantId, $statusId);
        self::validarCategoria($category);
        self::validarColor($color);
        $antes = self::problemas($tenantId, $repo);

        $repo->updateStatus($statusId, trim($name), $category, $color, $sortOrder, $isFinal);
        self::ensureNotWorse($tenantId, $repo, $antes);

        return $repo->get($tenantId, $actual->id);
    }

    public static function setInitial(string $tenantId, string $statusId): OrderStatusRow
    {
        $repo = new OrderStatusRepository(self::pdo());
        $status = self::estado($repo, $tenantId, $statusId);
        if ($status->isFinal) {
            throw new AdminError("'{$status->name}' es un estado final: si fuera el inicial, todo pedido naceria cerrado");
        }

        $antes = self::problemas($tenantId, $repo);
        $repo->moveInitial($tenantId, $statusId);
        self::ensureNotWorse($tenantId, $repo, $antes);
        return $repo->get($tenantId, $statusId);
    }

    /**
     * Borrar un estado arrastra sus transiciones (ON DELETE CASCADE), asi que
     * puede dejar sin salida a otro: por eso se revalida todo despues.
     */
    public static function deleteStatus(string $tenantId, string $statusId): void
    {
        $repo = new OrderStatusRepository(self::pdo());
        $status = self::estado($repo, $tenantId, $statusId);

        // orders.status_id y order_status_history.status_id son claves foraneas
        // sin ON DELETE: Postgres lo bloquearia. Se mira antes para poder decir
        // por que, y porque borrar historia no es una opcion.
        $pedidos = $repo->countOrdersIn($statusId);
        if ($pedidos > 0) {
            throw new AdminError(
                "Hay {$pedidos} pedido(s) en '{$status->name}': muevelos a otro estado antes de borrarlo"
            );
        }
        if ($repo->countHistoryFor($statusId) > 0) {
            throw new AdminError(
                "'{$status->name}' aparece en la bitacora de pedidos anteriores, asi que no se puede borrar. "
                . 'Quitale las transiciones que llevan a el y deja de usarlo.'
            );
        }

        $antes = self::problemas($tenantId, $repo);
        $repo->deleteStatus($statusId);
        self::ensureNotWorse($tenantId, $repo, $antes);
    }

    /**
     * Fija las salidas de un estado.
     *
     * @param array<int, array{to_status_id: string, required_permission: ?string}> $targets
     */
    public static function setTransitions(string $tenantId, string $statusId, array $targets): void
    {
        $repo = new OrderStatusRepository(self::pdo());
        $desde = self::estado($repo, $tenantId, $statusId);

        $conocidos = [];
        foreach ($repo->listStatuses($tenantId) as $s) {
            $conocidos[$s->id] = $s;
        }

        $normalizados = [];
        $vistos = [];
        foreach ($targets as $target) {
            $to = $target['to_status_id'];
            if (!isset($conocidos[$to])) {
                throw new AdminError('Uno de los estados de destino no existe para este restaurante');
            }
            if ($to === $statusId) {
                throw new AdminError("'{$desde->name}' no puede llevar a si mismo");
            }
            if (isset($vistos[$to])) {
                continue; // la llave (from, to) es unica: repetirla abortaria el INSERT
            }
            $vistos[$to] = true;

            $permiso = $target['required_permission'];
            if ($permiso !== null && !Permissions::exists($permiso)) {
                throw new AdminError("Permiso desconocido: {$permiso}");
            }
            $normalizados[] = [$to, $permiso];
        }

        $antes = self::problemas($tenantId, $repo);
        $repo->setTransitionsFrom($statusId, $normalizados);
        self::ensureNotWorse($tenantId, $repo, $antes);
    }

    // ---------- Auxiliares ----------

    /** Los problemas que impiden operar, tal como esta la configuracion ahora. */
    private static function problemas(string $tenantId, OrderStatusRepository $repo): array
    {
        return StatusMachineRules::problems(
            self::asDomain($repo->listStatuses($tenantId)),
            $repo->listTransitions($tenantId),
        );
    }

    /**
     * Relee la configuracion y se niega si la edicion introdujo un problema
     * nuevo. Como corre dentro de la transaccion de la peticion, lanzar aqui
     * deshace la edicion entera.
     *
     * Se compara contra los problemas que ya habia y no se exige una
     * configuracion impecable: si no, una rota no se podria arreglar desde la
     * web, porque cada paso de la reparacion chocaria con lo que el paso
     * siguiente iba a resolver.
     *
     * @param string[] $antes
     */
    private static function ensureNotWorse(string $tenantId, OrderStatusRepository $repo, array $antes): void
    {
        try {
            StatusMachineRules::ensureNotWorse($antes, self::problemas($tenantId, $repo));
        } catch (StatusConfigError $e) {
            throw new AdminError($e->getMessage());
        }
    }

    /**
     * @param OrderStatusRow[] $rows
     * @return OrderStatus[]
     */
    private static function asDomain(array $rows): array
    {
        return array_map(static fn (OrderStatusRow $r) => $r->toDomain(), $rows);
    }

    private static function estado(OrderStatusRepository $repo, string $tenantId, string $statusId): OrderStatusRow
    {
        $status = $repo->get($tenantId, $statusId);
        if ($status === null) {
            throw new AdminError('El estado no existe para este restaurante');
        }
        return $status;
    }

    private static function validarCategoria(string $category): void
    {
        if (!in_array($category, OrderStatus::CATEGORIES, true)) {
            throw new AdminError(
                "Categoria desconocida: {$category}. Validas: " . implode(', ', OrderStatus::CATEGORIES)
            );
        }
    }

    private static function validarColor(?string $color): void
    {
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) !== 1) {
            throw new AdminError("Color invalido: '{$color}'. Se espera un hexadecimal como #f59e0b");
        }
    }

    /**
     * Un codigo estable derivado del nombre.
     *
     * El codigo identifica al estado en la base (`UNIQUE (tenant_id, code)`)
     * pero no se usa en ninguna decision de negocio —para eso esta la
     * categoria—, asi que se genera y no se pide: una cosa menos que
     * inventar al crear un estado.
     */
    private static function codigoLibre(OrderStatusRepository $repo, string $tenantId, string $name): string
    {
        $base = strtolower(trim($name));
        $base = strtr($base, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'estado';
        }
        $base = substr($base, 0, 34);

        $code = $base;
        for ($i = 2; $repo->getByCode($tenantId, $code) !== null; $i++) {
            $code = "{$base}_{$i}";
        }
        return $code;
    }
}
