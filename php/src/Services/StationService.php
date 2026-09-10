<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\StationRepository;

/**
 * Las estaciones de preparacion y que categorias van a cada una.
 *
 * Configuracion, como los estados o los horarios: se edita desde la web y
 * ningun restaurante necesita tenerlas. Sin ninguna, la comanda sale entera
 * como antes de F8.1.
 */
final class StationService
{
    public const NOMBRE_MAX = 60;

    private static function repo(): StationRepository
    {
        return new StationRepository(Database::app());
    }

    /** @return array<int, array<string, mixed>> */
    public static function list(string $tenantId): array
    {
        return self::repo()->listForTenant($tenantId);
    }

    public static function create(string $tenantId, string $name, int $sortOrder = 0): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new StationError('La estacion necesita un nombre');
        }

        foreach (self::repo()->listForTenant($tenantId) as $existente) {
            if (mb_strtolower($existente['name']) === mb_strtolower($name)) {
                throw new StationError("Ya hay una estacion llamada '{$existente['name']}'");
            }
        }

        return self::repo()->create($tenantId, $name, $sortOrder);
    }

    public static function update(string $tenantId, string $stationId, string $name, bool $isActive): void
    {
        if (trim($name) === '') {
            throw new StationError('La estacion necesita un nombre');
        }
        if (!self::repo()->update($tenantId, $stationId, trim($name), $isActive)) {
            throw new StationError('Esa estacion no existe');
        }
    }

    /**
     * Borrar una estacion con categorias encima dejaria esos platos sin
     * comanda propia sin que nadie se entere. El mensaje dice cuantas hay
     * que mover, como con los estados y los grupos de modificadores.
     */
    public static function delete(string $tenantId, string $stationId): void
    {
        $repo = self::repo();
        $categorias = $repo->categoriesUsing($tenantId, $stationId);
        if ($categorias > 0) {
            throw new StationError(
                "Hay {$categorias} categoria(s) del menu mandando a esta estacion. "
                . 'Muevelas a otra —o dejalas sin estacion— antes de borrarla.'
            );
        }
        $repo->delete($tenantId, $stationId);
    }

    /** Manda una categoria a una estacion; null la devuelve a la comanda general. */
    public static function assignCategory(string $tenantId, string $categoryId, ?string $stationId): void
    {
        $repo = self::repo();

        if ($stationId !== null) {
            $existe = false;
            foreach ($repo->listForTenant($tenantId) as $estacion) {
                $existe = $existe || $estacion['id'] === $stationId;
            }
            if (!$existe) {
                throw new StationError('Esa estacion no existe para este tenant');
            }
        }

        if (!$repo->assignCategory($tenantId, $categoryId, $stationId)) {
            throw new StationError('Esa categoria no existe para este tenant');
        }
    }
}
