<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;

/**
 * Cursor de paginacion de la lista de pedidos.
 *
 * Keyset y no OFFSET: la lista se ordena por fecha descendente y mientras el
 * cajero pagina siguen entrando pedidos nuevos. Con OFFSET, cada pedido que
 * entra empuja la ventana y hace que la pagina siguiente repita una fila que
 * ya se vio. Con el par (created_at, id) del ultimo visto, "lo que sigue" no
 * depende de cuantos hayan entrado arriba.
 *
 * El id desempata: dos pedidos pueden compartir el instante y sin el
 * segundo criterio uno de los dos se perderia entre paginas.
 */
final class OrderCursor
{
    private function __construct()
    {
    }

    public static function encode(Order $order): string
    {
        return rtrim(strtr(base64_encode($order->createdAt . '|' . $order->id), '+/', '-_'), '=');
    }

    /**
     * Es opaco para el cliente, pero llega por la URL y cualquiera puede
     * inventarse uno: se valida antes de que su contenido toque una consulta.
     *
     * @return array{0: string, 1: string} created_at e id del ultimo visto
     */
    public static function decode(string $cursor): array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|')) {
            throw new OrderError('El cursor de paginacion no es valido');
        }

        [$createdAt, $id] = explode('|', $raw, 2);
        if ($createdAt === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new OrderError('El cursor de paginacion no es valido');
        }
        if (strtotime($createdAt) === false) {
            throw new OrderError('El cursor de paginacion no es valido');
        }

        return [$createdAt, $id];
    }
}
