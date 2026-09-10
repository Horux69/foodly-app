<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * La comanda de cocina, en ESC/POS (F8.3).
 *
 * Mismo documento que `web/js/views/impresion.js:imprimirComanda`: una hoja
 * por estación, sin precios —a la cocina el dinero no le sirve— y sin las
 * notas de preparación en el ticket. Reparte igual que la comanda impresa
 * por el navegador y el tablero de cocina, porque los tres leen
 * `kitchen_tickets` tal como lo arma `Domain\KitchenTickets`: si cada uno
 * agrupara por su lado, tarde o temprano dirían cosas distintas.
 */
final class EscPosComanda
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $pedido la forma de GET /orders/{id}
     * @param int|null $curso solo ese tiempo, cuando se manda al marcharlo
     * @return array<int, string> una hoja por estación
     */
    public static function build(array $pedido, int $ancho, bool $reimpresion = false, ?int $curso = null): array
    {
        $grupos = self::comandasDe($pedido);
        if ($curso !== null) {
            $grupos = array_values(array_filter($grupos, static fn (array $g) => ($g['course'] ?? 1) === $curso));
        }

        $variasEstaciones = count($grupos) > 1;
        return array_map(
            static fn (array $grupo) => self::hoja($pedido, $grupo, $ancho, $reimpresion, $variasEstaciones),
            $grupos,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function comandasDe(array $pedido): array
    {
        if (!empty($pedido['kitchen_tickets'])) {
            return $pedido['kitchen_tickets'];
        }
        // Sin estaciones configuradas —el caso de casi todos— una sola
        // comanda con todo el pedido, como siempre.
        return [[
            'station_id' => null,
            'station_name' => null,
            'course' => 1,
            'course_name' => null,
            'lines' => $pedido['items'],
        ]];
    }

    private static function hoja(array $pedido, array $grupo, int $ancho, bool $reimpresion, bool $variasEstaciones): string
    {
        $out = EscPos::INIT . EscPos::align('center');

        if ($reimpresion) {
            $out .= EscPos::doubleSize(true) . EscPos::bold(true)
                . EscPos::linea('REIMPRESION')
                . EscPos::bold(false) . EscPos::doubleSize(false)
                . EscPos::linea('puede estar ya preparado')
                . EscPos::separador($ancho, '=');
        }

        $out .= EscPos::doubleSize(true) . EscPos::linea('COMANDA') . EscPos::doubleSize(false);
        $out .= EscPos::bold(true) . EscPos::linea((string) $pedido['order_number']) . EscPos::bold(false);

        $lugar = !empty($pedido['table_code'])
            ? "Mesa {$pedido['table_code']}"
            : EscPos::nombreCanal((string) $pedido['channel']);
        $out .= EscPos::linea($lugar);

        if ($variasEstaciones && !empty($grupo['station_name'])) {
            $out .= EscPos::bold(true) . EscPos::linea(strtoupper((string) $grupo['station_name'])) . EscPos::bold(false);
        }
        if (!empty($grupo['course_name'])) {
            $out .= EscPos::bold(true) . EscPos::linea(strtoupper((string) $grupo['course_name'])) . EscPos::bold(false);
        }

        $out .= EscPos::align('left') . EscPos::separador($ancho);

        foreach ($grupo['lines'] as $item) {
            $out .= EscPos::linea("{$item['quantity']}x {$item['name_snapshot']}");
            foreach (($item['components'] ?? []) as $c) {
                $out .= EscPos::linea("  {$c['quantity']}x {$c['name_snapshot']}");
            }
            foreach (($item['modifiers'] ?? []) as $m) {
                // Como en el navegador: los modificadores llegan como texto
                // desde el KDS y como objeto desde el detalle.
                $out .= EscPos::linea('  ' . (is_string($m) ? $m : $m['name_snapshot']));
            }
            if (!empty($item['notes'])) {
                $out .= EscPos::linea("  NOTA: {$item['notes']}");
            }
        }

        if (!empty($pedido['notes'])) {
            $out .= EscPos::separador($ancho) . EscPos::linea((string) $pedido['notes']);
        }
        if (!empty($pedido['delivery']['address'])) {
            $out .= EscPos::linea('Domicilio: ' . $pedido['delivery']['address']);
        }

        return $out . "\n\n\n" . EscPos::CUT;
    }
}
