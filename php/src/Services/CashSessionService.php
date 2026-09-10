<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\CashSessionTotals;
use App\Domain\DrawerError;
use App\Domain\DrawerRules;
use App\Models\CashSession;
use App\Repositories\BranchRepository;
use App\Repositories\CashSessionRepository;

/**
 * Turnos de caja y arqueo (fase 2).
 *
 * Un turno es un intervalo con una base de apertura y un conteo de cierre; lo
 * que lo vuelve util es que cada cobro sepa a cual pertenece
 * (`payments.cash_session_id`, que asienta PaymentService). Aqui se orquesta
 * el ciclo; la aritmetica del cuadre vive entera en
 * Domain\CashSessionTotals.
 *
 * Cobrar no exige tener un turno abierto: un restaurante que no lleve caja
 * por turnos debe poder seguir vendiendo. Lo que se cobra sin turno abierto
 * queda con `cash_session_id` nulo y simplemente no entra en ningun arqueo.
 */
final class CashSessionService
{
    /** unique_violation de Postgres. */
    private const UNIQUE_VIOLATION = '23505';

    private static function repo(): CashSessionRepository
    {
        return new CashSessionRepository(Database::app());
    }

    public static function current(string $tenantId, string $branchId): ?CashSession
    {
        return self::repo()->currentForBranch($tenantId, $branchId);
    }

    public static function open(
        string $tenantId,
        string $branchId,
        ?string $userId,
        int $openingFloatCents,
    ): CashSessionView {
        if ($openingFloatCents < 0) {
            throw new CashSessionError('La base de caja no puede ser negativa');
        }

        $pdo = Database::app();
        if ((new BranchRepository($pdo))->get($tenantId, $branchId) === null) {
            throw new CashSessionError('La sucursal no existe para este tenant');
        }

        $repo = self::repo();
        if ($repo->currentForBranch($tenantId, $branchId) !== null) {
            throw new CashSessionError('Esta sucursal ya tiene un turno de caja abierto');
        }

        // La comprobacion de arriba da el mensaje bueno; esta atrapa la
        // carrera —dos cajeros abriendo a la vez pasan los dos por ella— que
        // el indice unico parcial de la migracion 006 corta de verdad.
        // Con SAVEPOINT porque un INSERT fallido aborta la transaccion de la
        // peticion entera.
        $pdo->exec('SAVEPOINT abrir_turno');
        try {
            $session = $repo->open($tenantId, $branchId, $userId, $openingFloatCents);
        } catch (\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT abrir_turno');
            if ($e->getCode() === self::UNIQUE_VIOLATION) {
                throw new CashSessionError('Esta sucursal ya tiene un turno de caja abierto');
            }
            throw $e;
        }
        $pdo->exec('RELEASE SAVEPOINT abrir_turno');

        return self::view($session);
    }

    public static function close(
        string $tenantId,
        string $sessionId,
        ?string $userId,
        int $countedCashCents,
        ?string $note,
    ): CashSessionView {
        if ($countedCashCents < 0) {
            throw new CashSessionError('El efectivo contado no puede ser negativo');
        }

        $repo = self::repo();
        $session = $repo->get($tenantId, $sessionId);
        if ($session === null) {
            throw new CashSessionError('El turno de caja no existe para este tenant');
        }
        if (!$session->isOpen()) {
            throw new CashSessionError('Ese turno ya se cerro');
        }

        return self::view($repo->close($tenantId, $sessionId, $userId, $countedCashCents, $note));
    }

    /** El turno con su cuadre. */
    public static function view(CashSession $session): CashSessionView
    {
        $repo = self::repo();
        $movements = $repo->movementsForSessions([$session->id])[$session->id] ?? [];
        $drawer = $repo->drawerForSessions([$session->id])[$session->id] ?? [];

        return new CashSessionView(
            $session,
            CashSessionTotals::compute(
                $session->openingFloatCents,
                $movements,
                $session->countedCashCents,
                CashSessionTotals::CASH_METHOD,
                $drawer,
            ),
        );
    }

    /**
     * Registra plata que entra o sale del cajon sin ser una venta.
     *
     * Exige un turno abierto: fuera de un turno no hay cajon que cuadrar, y
     * un movimiento suelto no entraria en ningun arqueo — que es justo lo que
     * esto viene a arreglar.
     *
     * @return array{0: CashSessionView, 1: string} el turno recalculado y el id del movimiento
     */
    public static function registerDrawerMovement(
        string $tenantId,
        string $branchId,
        string $kind,
        int $amountCents,
        string $reason,
        ?string $userId,
    ): array {
        $repo = self::repo();
        $session = $repo->currentForBranch($tenantId, $branchId);
        if ($session === null) {
            throw new CashSessionError('No hay un turno de caja abierto en esta sucursal');
        }

        // Contra lo que hay ahora mismo en el cajon, no contra lo cobrado:
        // sacar mas de lo que hay dejaria el arqueo en una cifra que nadie
        // puede explicar.
        try {
            DrawerRules::validate($kind, $amountCents, $reason, self::view($session)->totals->expectedCashCents);
        } catch (DrawerError $e) {
            throw new CashSessionError($e->getMessage());
        }

        $id = $repo->addDrawerMovement($session->id, $kind, $amountCents, trim($reason), $userId);

        return [self::view($session), $id];
    }

    /**
     * Los movimientos del turno abierto, para listarlos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function drawerMovements(string $tenantId, string $branchId): array
    {
        $session = self::repo()->currentForBranch($tenantId, $branchId);
        return $session === null ? [] : self::repo()->drawerDetail($session->id);
    }

    /**
     * Los ultimos turnos de la sucursal, con su cuadre.
     *
     * Los movimientos de todos salen en una consulta: pedir uno por turno
     * seria el mismo N+1 que se quito de la lista de pedidos.
     *
     * @return CashSessionView[]
     */
    public static function history(string $tenantId, string $branchId, int $limit = 30): array
    {
        $repo = self::repo();
        $sessions = $repo->listForBranch($tenantId, $branchId, $limit);
        $ids = array_map(static fn ($s) => $s->id, $sessions);
        $movements = $repo->movementsForSessions($ids);
        $drawer = $repo->drawerForSessions($ids);

        return array_map(
            static fn (CashSession $s) => new CashSessionView(
                $s,
                CashSessionTotals::compute(
                    $s->openingFloatCents,
                    $movements[$s->id] ?? [],
                    $s->countedCashCents,
                    CashSessionTotals::CASH_METHOD,
                    $drawer[$s->id] ?? [],
                ),
            ),
            $sessions,
        );
    }
}
