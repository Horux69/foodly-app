<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * La forma de una zona de reparto dibujada en el mapa (F9.3).
 *
 * `delivery_zones.polygon` existe desde el esquema inicial y nadie lo
 * llenaba: la zona se identificaba solo por nombre, y quien tomaba un
 * domicilio elegía de una lista sin ver dónde empieza y dónde termina cada
 * una. Dibujarla no cambia esa selección —sigue siendo manual, como
 * siempre—, es la referencia visual para configurarla bien.
 *
 * Sin polígono (`null`) la zona sigue funcionando igual que hoy: es
 * configuración opcional, no un requisito nuevo.
 */
final class DeliveryZonePolygon
{
    /** Menos de tres puntos no encierra ningún área. */
    public const MIN_PUNTOS = 3;

    /** Tope de sanidad: un polígono dibujado a mano no necesita miles de vértices. */
    public const MAX_PUNTOS = 300;

    private function __construct()
    {
    }

    /**
     * Valida y normaliza los puntos que llegan del cliente.
     *
     * @param array<mixed> $puntos cada uno como [lat, lng] — la forma que produce Leaflet
     * @return array<int, array{lat: float, lng: float}>
     * @throws DeliveryZonePolygonError
     */
    public static function validate(array $puntos): array
    {
        $cantidad = count($puntos);
        if ($cantidad < self::MIN_PUNTOS) {
            throw new DeliveryZonePolygonError(
                'Una zona dibujada necesita al menos ' . self::MIN_PUNTOS . ' puntos'
            );
        }
        if ($cantidad > self::MAX_PUNTOS) {
            throw new DeliveryZonePolygonError('Demasiados puntos: el máximo es ' . self::MAX_PUNTOS);
        }

        $normalizado = [];
        foreach ($puntos as $punto) {
            if (!is_array($punto) || count($punto) !== 2) {
                throw new DeliveryZonePolygonError('Cada punto necesita latitud y longitud');
            }

            [$lat, $lng] = array_values($punto);
            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new DeliveryZonePolygonError('Cada punto necesita latitud y longitud numéricas');
            }

            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new DeliveryZonePolygonError('Un punto quedó fuera del mapa');
            }

            $normalizado[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $normalizado;
    }
}
