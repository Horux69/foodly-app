<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\RawResponse;
use App\Api\Request;
use App\Core\Database;
use App\Core\Money;
use App\Domain\EscPos;
use App\Domain\EscPosComanda;
use App\Domain\EscPosTicket;
use App\Domain\PrintProfile;
use App\Domain\TenantSettings;
use App\Domain\TipRules;
use App\Models\Branch;
use App\Models\Tenant;
use App\Repositories\BranchRepository;
use App\Repositories\PrintProfileRepository;
use App\Repositories\TenantRepository;
use App\Services\OrderService;

/**
 * El mismo documento que ya arma el navegador, en ESC/POS (F8.3).
 *
 * Esto es solo el contrato: los bytes que un agente local le mandaría a una
 * impresora térmica. Ese agente —el que de verdad habla con el hardware, por
 * USB, serial o red— es un binario fuera de este repositorio, con su propia
 * decisión de plataforma; aquí solo vive qué imprimir, con exactamente los
 * mismos datos que ya sirven `GET /orders/{id}`, `GET /orders/{id}/payments`
 * y `GET /orders/{id}/fiscal-document` para el ticket que arma el navegador.
 *
 * Se autoriza igual que ver el pedido (`orders.view`): esto no imprime nada
 * por sí solo, es una proyección de lectura de los mismos datos que el
 * cliente ya puede ver en pantalla.
 */
final class EscPosController
{
    public static function ticket(array $params): RawResponse
    {
        $orderId = $params['order_id'];
        [$pedido, $tenant, $sucursal, $ancho] = self::contexto($orderId, PrintProfile::TICKET);

        $pagos = PaymentController::list(['order_id' => $orderId]);
        $fiscal = FiscalController::show(['order_id' => $orderId])['document'];
        $precuenta = Request::queryBool('precuenta');

        // La propina sugerida es la misma cuenta que ya hace `pedido-detalle.js`
        // (`propinaSugerida`) sobre `Domain\TipRules`, no una segunda regla:
        // solo tiene sentido en la pre-cuenta, y solo si nadie puso ya una.
        $ajustes = TenantSettings::parse($tenant->settings, $tenant->businessType);
        $propinaSugeridaCents = ($precuenta && $ajustes->asksTip && (float) $pedido['tip'] === 0.0)
            ? TipRules::suggestCents(Money::fromDecimalString((string) $pedido['subtotal']), $ajustes->tipPercent)
            : 0;

        $bytes = EscPosTicket::build(
            $pedido,
            $pagos,
            $tenant->name,
            ['name' => $sucursal->name, 'address' => $sucursal->address, 'phone' => $sucursal->phone],
            $fiscal,
            $tenant->currency,
            $sucursal->timezone,
            $ancho,
            $precuenta,
            $propinaSugeridaCents,
        );

        return self::salida($bytes);
    }

    public static function comanda(array $params): RawResponse
    {
        $orderId = $params['order_id'];
        [$pedido, , , $ancho] = self::contexto($orderId, PrintProfile::COMANDA);

        $reimpresion = Request::queryBool('reimpresion');
        // 0 es "sin filtro": la comanda entera, como al tomar el pedido. Un
        // numero real solo llega al reimprimir el papel de un tiempo puntual.
        $curso = Request::queryInt('curso', default: 0, min: 0, max: 50);

        $hojas = EscPosComanda::build($pedido, $ancho, $reimpresion, $curso === 0 ? null : $curso);

        // Una hoja por estacion, concatenadas: cada una ya trae su propio
        // corte (`EscPos::CUT`), asi que llegan separadas al papel igual que
        // llegarian una por una.
        return self::salida(implode('', $hojas));
    }

    /** @return array{0: array<string, mixed>, 1: Tenant, 2: Branch, 3: int} */
    private static function contexto(string $orderId, string $documento): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');

        $orden = OrderService::getOrder($ctx->tenantId, $orderId);
        if ($orden === null) {
            throw new ApiException(404, 'Pedido no encontrado');
        }

        $pdo = Database::app();
        $tenant = (new TenantRepository($pdo))->get($ctx->tenantId);
        $sucursal = (new BranchRepository($pdo))->get($ctx->tenantId, $orden->branchId);
        if ($tenant === null || $sucursal === null) {
            throw new ApiException(404, 'Pedido no encontrado');
        }

        // El ancho es del papel de ESTA sucursal, la del pedido: la impresora
        // esta ahi, sin importar desde donde se pida el documento.
        $guardados = (new PrintProfileRepository($pdo))->forBranch($orden->branchId);
        $perfiles = array_column(PrintProfile::completar($guardados), null, 'document');
        $ancho = EscPos::anchoDeColumnas($perfiles[$documento]->widthMm);

        $pedido = OrderController::get(['order_id' => $orderId]);

        return [$pedido, $tenant, $sucursal, $ancho];
    }

    private static function salida(string $bytes): RawResponse
    {
        // No es JSON ni un archivo que el navegador deba guardar: es la
        // entrada de un agente que todavia no existe, así que no hay
        // Content-Disposition ni nombre de archivo que ofrecer.
        return new RawResponse($bytes, 'application/vnd.escpos');
    }
}
