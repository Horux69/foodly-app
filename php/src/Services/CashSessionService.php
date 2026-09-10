<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\CashSessionTotals;
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
        $movements = self::repo()->movementsForSessions([$session->id])[$session->id] ?? [];

        return new CashSessionView(
            $session,
            CashSessionTotals::compute($session->openingFloatCents, $movements, $session->countedCashCents),
        );
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
        $movements = $repo->movementsForSessions(array_map(static fn ($s) => $s->id, $sessions));

        return array_map(
            static fn (CashSession $s) => new CashSessionView(
                $s,
                CashSessionTotals::compute($s->openingFloatCents, $movements[$s->id] ?? [], $s->countedCashCents),
            ),
            $sessions,
        );
    }
}
