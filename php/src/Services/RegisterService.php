<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Register;
use App\Repositories\BranchRepository;
use App\Repositories\RegisterRepository;

/** Las cajas de una sucursal (F7.4). */
final class RegisterService
{
    /** @return Register[] */
    public static function listForBranch(string $tenantId, string $branchId, bool $soloActivas = false): array
    {
        self::ownBranch($tenantId, $branchId);
        return (new RegisterRepository(Database::app()))->listForBranch($branchId, $soloActivas);
    }

    public static function create(string $tenantId, string $branchId, string $name): Register
    {
        self::ownBranch($tenantId, $branchId);
        $repo = new RegisterRepository(Database::app());

        $name = trim($name);
        if ($name === '') {
            throw new RegisterError('La caja necesita un nombre');
        }
        if ($repo->getByName($branchId, $name) !== null) {
            throw new RegisterError("Ya existe una caja llamada '{$name}' en esta sucursal");
        }

        return $repo->create($tenantId, $branchId, $name);
    }

    public static function update(string $tenantId, string $id, string $name, bool $isActive): Register
    {
        $repo = new RegisterRepository(Database::app());
        $actual = $repo->get($tenantId, $id);
        if ($actual === null) {
            throw new RegisterError('Esa caja no existe');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RegisterError('La caja necesita un nombre');
        }
        $otra = $repo->getByName($actual->branchId, $name);
        if ($otra !== null && $otra->id !== $id) {
            throw new RegisterError("Ya existe una caja llamada '{$name}' en esta sucursal");
        }

        $registro = $repo->update($tenantId, $id, $name, $isActive);
        if ($registro === null) {
            throw new RegisterError('Esa caja no existe');
        }
        return $registro;
    }

    /**
     * Una caja con turnos encima no se borra, se apaga.
     *
     * Igual que un origen de venta con ventas, o un motivo de descuento
     * usado: borrarla dejaria arqueos historicos sin decir de que cajon
     * eran. La clave foranea lo bloquearia igual; el mensaje dice cuantos
     * turnos hay.
     */
    public static function delete(string $tenantId, string $id): void
    {
        $repo = new RegisterRepository(Database::app());
        if ($repo->get($tenantId, $id) === null) {
            throw new RegisterError('Esa caja no existe');
        }
        $cuantos = $repo->sessionCount($id);
        if ($cuantos > 0) {
            throw new RegisterError(
                "Esta caja tiene {$cuantos} turno(s) de caja: apagala en vez de borrarla, o el arqueo historico se quedaria sin decir de cual era"
            );
        }
        if (!$repo->delete($tenantId, $id)) {
            throw new RegisterError('Esa caja no existe');
        }
    }

    private static function ownBranch(string $tenantId, string $branchId): void
    {
        if ((new BranchRepository(Database::app()))->get($tenantId, $branchId) === null) {
            throw new RegisterError('La sucursal no existe para este tenant');
        }
    }
}
