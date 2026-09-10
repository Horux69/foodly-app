<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * El ticket de cliente, en ESC/POS (F8.3).
 *
 * Mismo documento que arma `web/js/views/impresion.js:imprimirTicket`, con
 * los mismos datos —lo que ya calculó el backend, nada se suma aquí— pero en
 * texto plano de ancho fijo en vez de HTML. Toma las respuestas tal como las
 * sirve la API (`GET /orders/{id}`, `GET /orders/{id}/payments`,
 * `GET /orders/{id}/fiscal-document`): el mismo contrato que ya consume el
 * navegador, para que un documento nuevo no invente una segunda forma de
 * pedir lo mismo.
 */
final class EscPosTicket
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $pedido la forma de GET /orders/{id}
     * @param array<int, array<string, mixed>> $pagos la forma de GET /orders/{id}/payments
     * @param array{name: string, address: ?string, phone: ?string} $sede
     * @param array<string, mixed>|null $fiscal el campo `document` de GET /orders/{id}/fiscal-document
     */
    public static function build(
        array $pedido,
        array $pagos,
        string $tenantName,
        array $sede,
        ?array $fiscal,
        string $currency,
        string $timezone,
        int $ancho,
        bool $precuenta = false,
        int $propinaSugeridaCents = 0,
    ): string {
        $out = EscPos::INIT;

        if ($precuenta) {
            $out .= EscPos::align('center') . EscPos::bold(true)
                . EscPos::linea('PRE-CUENTA')
                . EscPos::linea('NO ES FACTURA DE VENTA')
                . EscPos::bold(false) . EscPos::separador($ancho, '=');
        }

        $out .= EscPos::align('center') . EscPos::doubleSize(true)
            . EscPos::linea($tenantName)
            . EscPos::doubleSize(false)
            . EscPos::linea($sede['name']);
        if (!empty($sede['address'])) {
            $out .= EscPos::linea($sede['address']);
        }
        if (!empty($sede['phone'])) {
            $out .= EscPos::linea("Tel. {$sede['phone']}");
        }
        $out .= EscPos::bold(true) . EscPos::linea((string) $pedido['order_number']) . EscPos::bold(false);

        // El numero autorizado nunca va en una pre-cuenta: con el encima, el
        // papel se lee como el comprobante que todavia no es.
        if ($fiscal !== null && !$precuenta) {
            $out .= EscPos::linea((string) $fiscal['full_number']);
            if (!empty($fiscal['external_id'])) {
                $out .= EscPos::linea((string) $fiscal['external_id']);
            }
        }

        $lugar = $pedido['table_code'] !== null && $pedido['table_code'] !== ''
            ? "Mesa {$pedido['table_code']}"
            : EscPos::nombreCanal((string) $pedido['channel']);
        $out .= EscPos::linea($lugar . ' - ' . EscPos::fechaHora((string) $pedido['created_at'], $timezone));

        $out .= EscPos::align('left') . EscPos::separador($ancho);

        foreach ($pedido['items'] as $item) {
            $out .= EscPos::fila(
                "{$item['quantity']}x {$item['name_snapshot']}",
                self::moneda((string) $item['line_total'], $currency),
                $ancho,
            );
            foreach (($item['components'] ?? []) as $c) {
                $out .= EscPos::linea("  {$c['quantity']}x {$c['name_snapshot']}");
            }
            foreach (($item['modifiers'] ?? []) as $m) {
                $out .= EscPos::linea('  ' . $m['name_snapshot']);
            }
        }

        $out .= EscPos::separador($ancho);
        $out .= EscPos::fila('Subtotal', self::moneda((string) $pedido['subtotal'], $currency), $ancho);
        if ((float) $pedido['delivery_fee'] > 0) {
            $out .= EscPos::fila('Domicilio', self::moneda((string) $pedido['delivery_fee'], $currency), $ancho);
        }
        if ((float) $pedido['discount'] > 0) {
            $out .= EscPos::fila('Descuento', '-' . self::moneda((string) $pedido['discount'], $currency), $ancho);
        }
        if ((float) $pedido['tip'] > 0) {
            $out .= EscPos::fila('Propina', self::moneda((string) $pedido['tip'], $currency), $ancho);
        }
        $out .= EscPos::bold(true)
            . EscPos::fila('TOTAL', self::moneda((string) $pedido['total'], $currency), $ancho)
            . EscPos::bold(false);
        if ((float) $pedido['tax_total'] > 0) {
            $out .= EscPos::fila('Impuesto incluido', self::moneda((string) $pedido['tax_total'], $currency), $ancho);
        }
        if ($propinaSugeridaCents > 0) {
            $sugeridaDecimal = number_format($propinaSugeridaCents / 100, 2, '.', '');
            $totalConPropina = number_format(((float) $pedido['total']) + $propinaSugeridaCents / 100, 2, '.', '');
            $out .= EscPos::fila('Propina sugerida (voluntaria)', self::moneda($sugeridaDecimal, $currency), $ancho);
            $out .= EscPos::fila('Total con propina', self::moneda($totalConPropina, $currency), $ancho);
        }

        if ($pagos !== []) {
            $out .= EscPos::separador($ancho);
            foreach ($pagos as $p) {
                $esReembolso = !empty($p['refund_of_payment_id']);
                $etiqueta = ($esReembolso ? 'Reembolso ' : '') . self::nombreMetodo((string) $p['method']);
                $importe = ($esReembolso ? '-' : '') . self::moneda((string) $p['amount'], $currency);
                $out .= EscPos::fila($etiqueta, $importe, $ancho);
            }
            $saldo = $pedido['balance'] ?? null;
            if ($saldo !== null && !$saldo['is_settled']) {
                $out .= EscPos::fila('Falta por pagar', self::moneda((string) $saldo['pending'], $currency), $ancho);
            }
        }

        $out .= EscPos::separador($ancho) . EscPos::align('center');
        $out .= EscPos::linea($precuenta ? 'Este documento no es un comprobante de pago.' : 'Gracias por su compra!');
        $out .= "\n\n\n" . EscPos::CUT;

        return $out;
    }

    private static function nombreMetodo(string $code): string
    {
        return match ($code) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            default => $code,
        };
    }

    /**
     * Sin tabla de simbolos por moneda: "COP 18.000" en vez de "$ 18.000"
     * funciona para cualquier `currency` sin adivinar un simbolo que no le
     * corresponde.
     */
    private static function moneda(string $decimal, string $currency): string
    {
        $pesos = (int) round(((float) $decimal));
        return $currency . ' ' . number_format($pesos, 0, ',', '.');
    }
}
