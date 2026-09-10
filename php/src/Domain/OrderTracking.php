<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Donde va el pedido, contado para quien lo espera.
 *
 * El cliente no necesita saber como se llaman los estados del restaurante
 * —"En moto", "Despachado", "Salio"— sino en cual de los cuatro momentos de
 * su pedido esta. Eso lo dice la **categoria** del estado, que es la misma
 * en todos los restaurantes (principio 6); el nombre configurado se muestra
 * al lado, porque es el que usa el restaurante cuando el cliente llama.
 *
 * Dos decisiones:
 *
 * - **Los pasos dependen de si es domicilio.** Un pedido para recoger no
 *   pasa por "en camino", y una linea de progreso con un paso que nunca se
 *   va a encender parece un pedido atascado.
 * - **Anulado no es un paso mas.** No es el final de la linea sino su
 *   interrupcion, y mostrarlo como el ultimo paso diria que el pedido se
 *   completo.
 */
final class OrderTracking
{
    /** El recorrido de un pedido que se recoge o se come ahi. */
    public const PASOS_LOCAL = ['Recibido', 'En preparación', 'Listo'];

    /** El de un domicilio, que ademas viaja. */
    public const PASOS_DOMICILIO = ['Recibido', 'En preparación', 'Listo', 'En camino', 'Entregado'];

    /** A que paso corresponde cada categoria. */
    private const POR_CATEGORIA = [
        'new' => 'Recibido',
        'kitchen' => 'En preparación',
        'ready' => 'Listo',
        'in_transit' => 'En camino',
        'completed' => 'Entregado',
    ];

    private function __construct()
    {
    }

    /** @return string[] */
    public static function pasos(bool $esDomicilio): array
    {
        // Un pedido de mostrador tambien se completa; su ultimo paso es
        // "Entregado" aunque no viaje.
        return $esDomicilio
            ? self::PASOS_DOMICILIO
            : [...self::PASOS_LOCAL, 'Entregado'];
    }

    /**
     * En cual de los pasos esta, contando desde 0.
     *
     * Null cuando el pedido se anulo: no esta en ningun punto del camino.
     */
    public static function paso(string $categoria, bool $esDomicilio): ?int
    {
        $nombre = self::POR_CATEGORIA[$categoria] ?? null;
        if ($nombre === null) {
            return null;
        }
        $indice = array_search($nombre, self::pasos($esDomicilio), true);
        return $indice === false ? null : $indice;
    }

    public static function anulado(string $categoria): bool
    {
        return $categoria === 'cancelled';
    }

    /**
     * El nombre de pila de quien lo lleva.
     *
     * Solo el primero: al cliente le sirve para reconocerlo en la puerta y
     * el apellido del empleado no es suyo para repartirlo.
     */
    public static function nombreCorto(?string $nombre): ?string
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }
        return explode(' ', trim($nombre))[0];
    }
}
