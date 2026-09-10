<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Core\Database;
use App\Api\Request;
use App\Domain\PrintProfile;
use App\Domain\PrintProfileError;
use App\Repositories\PrintProfileRepository;

/**
 * Perfiles de impresion de la sucursal activa (F8.2).
 *
 * Verlos no pide permiso de configuracion: los necesita cualquiera que
 * imprima, que es todo el mundo. Cambiarlos pide `branches.manage`, el mismo
 * permiso que decide como opera cada sede.
 */
final class PrintProfileController
{
    public static function index(): array
    {
        $ctx = Deps::requireAny(Deps::getContext(), 'orders.view', 'orders.create');
        $guardados = (new PrintProfileRepository(Database::app()))->forBranch(Deps::activeBranchId($ctx));

        return array_map(static fn (PrintProfile $p) => [
            'document' => $p->document,
            'width_mm' => $p->widthMm,
            'content_width_mm' => $p->contentWidthMm(),
            'copies' => $p->copies,
        ], PrintProfile::completar($guardados));
    }

    /** Se manda uno a la vez: cada documento se configura por su lado. */
    public static function save(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $branchId = Deps::activeBranchId($ctx);
        $body = Request::json();

        $ancho = Request::int($body, 'width_mm');
        $copias = Request::int($body, 'copies');

        try {
            PrintProfile::validate($params['document'], $ancho, $copias);
        } catch (PrintProfileError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        (new PrintProfileRepository(Database::app()))->upsert($branchId, $params['document'], $ancho, $copias);

        $perfil = new PrintProfile($params['document'], $ancho, $copias);

        return [
            'document' => $perfil->document,
            'width_mm' => $perfil->widthMm,
            'content_width_mm' => $perfil->contentWidthMm(),
            'copies' => $perfil->copies,
        ];
    }
}
