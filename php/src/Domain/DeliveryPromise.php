<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * La promesa de entrega y su incumplimiento.
 *
 * La zona ya traia `est_minutes` y no servia para nada: era un numero de
 * configuracion que ninguna pantalla miraba. Convertirlo en una hora
 * concreta por pedido es lo que permite lo unico que importa de un
 * domicilio despues de que sale: saber que se va a incumplir **antes** de
 * que el cliente llame. Un domicilio tarde que nadie vio es una reseña de
 * una estrella.
 *
 * Tres decisiones:
 *
 * - **La promesa se sella al tomar el pedido, no se recalcula.** Es lo que
 *   se le dijo al cliente; recalcularla con el tiempo restante haria que un
 *   pedido nunca llegara tarde, que es la unica forma de que el indicador
 *   no sirva para nada.
 * - **Sin zona no hay promesa, y eso se dice.** Prometer un tiempo
 *   inventado es peor que no prometer: el que no promete nada no incumple.
 * - **Hay un aviso antes del incumplimiento.** Cuando falta poco todavia se
 *   puede hacer algo —apurar la cocina, llamar al cliente—; enterarse al
 *   minuto siguiente de la hora prometida ya no sirve de nada.
 */
final class DeliveryPromise
{
    /** Minutos antes de la hora prometida en los que ya conviene mirar. */
    public const AVISO_MINUTOS = 10;

    public const A_TIEMPO = 'on_time';
    public const EN_RIESGO = 'at_risk';
    public const TARDE = 'late';
    public const SIN_PROMESA = 'none';

    private function __construct()
    {
    }

    /**
     * En que va la promesa de un pedido.
     *
     * @param ?string $promised hora prometida (ISO), null si no se prometio
     * @param ?string $delivered hora real de entrega, null si sigue en la calle
     * @param string $ahora el reloj, inyectado para poder probarlo
     */
    public static function estado(?string $promised, ?string $delivered, string $ahora = 'now'): string
    {
        if ($promised === null) {
            return self::SIN_PROMESA;
        }

        $prometida = new \DateTimeImmutable($promised);
        // Un pedido entregado se juzga contra su entrega; uno en la calle,
        // contra el reloj de ahora.
        $referencia = new \DateTimeImmutable($delivered ?? $ahora);

        if ($referencia > $prometida) {
            return self::TARDE;
        }
        if ($delivered !== null) {
            return self::A_TIEMPO;
        }

        $faltan = ($prometida->getTimestamp() - $referencia->getTimestamp()) / 60;
        return $faltan <= self::AVISO_MINUTOS ? self::EN_RIESGO : self::A_TIEMPO;
    }

    /**
     * Cuantos minutos de retraso lleva, redondeando hacia abajo.
     *
     * Cero cuando no hay promesa o cuando todavia no se ha pasado: un
     * "faltan -3 minutos" no lo lee nadie.
     */
    public static function retrasoMinutos(?string $promised, ?string $delivered, string $ahora = 'now'): int
    {
        if ($promised === null) {
            return 0;
        }
        $prometida = new \DateTimeImmutable($promised);
        $referencia = new \DateTimeImmutable($delivered ?? $ahora);
        $diferencia = ($referencia->getTimestamp() - $prometida->getTimestamp()) / 60;
        return $diferencia <= 0 ? 0 : (int) floor($diferencia);
    }
}
