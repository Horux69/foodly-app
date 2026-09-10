<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Que se le puede cambiar a un pedido que ya existe.
 *
 * Hasta ahora un pedido era inmutable: "quitame la gaseosa" obligaba a
 * anularlo —con motivo, que queda en el reporte de anulaciones como si algo
 * hubiera salido mal— y a volver a tomarlo entero. Es la operacion mas comun
 * de un mostrador.
 *
 * Las tres reglas responden a lo mismo que StatusChangeRules: que exige el
 * dinero, y donde esta el pedido en su ciclo.
 *
 * - **Se edita mientras el pedido siga en la casa.** Con el pedido entregado,
 *   en camino o anulado ya no hay nada que cambiar: lo que llego al cliente no
 *   se corrige editando la venta.
 * - **No se puede dejar el pedido por debajo de lo ya cobrado.** Quitar
 *   productos de un pedido pagado dejaria plata sin venta que la respalde, que
 *   es el mismo descuadre que impide anular un pedido cobrado. El camino es
 *   reembolsar primero.
 * - **Un pedido sin lineas no es un pedido.** Vaciarlo seria anularlo por la
 *   puerta de atras, sin motivo y sin quedar en el reporte.
 */
final class OrderEditRules
{
    /**
     * Categorias en las que el pedido todavia admite cambios.
     *
     * Por categoria y nunca por codigo (principio 6): cada restaurante
     * bautiza sus estados como quiere.
     */
    public const EDITABLES = ['new', 'kitchen'];

    private function __construct()
    {
    }

    public static function esEditable(string $categoria): bool
    {
        return in_array($categoria, self::EDITABLES, true);
    }

    /** @throws OrderEditError */
    public static function ensureEditable(string $categoria): void
    {
        if (!self::esEditable($categoria)) {
            throw new OrderEditError(
                'Este pedido ya no se puede modificar: solo se cambian los que siguen en preparacion'
            );
        }
    }

    /**
     * La cocina ya lo tiene, pero todavia se puede cambiar.
     *
     * No impide nada —hay que poder quitar el plato que el cliente cancelo
     * dos minutos despues de pedirlo— pero la pantalla avisa: puede que ya
     * este en la plancha.
     */
    public static function laCocinaYaLoTiene(string $categoria): bool
    {
        return $categoria === 'kitchen';
    }

    /** @throws OrderEditError */
    public static function ensureQuedanLineas(int $cuantas): void
    {
        if ($cuantas < 1) {
            throw new OrderEditError(
                'Un pedido no puede quedarse sin productos. Si ya no va, anulalo con su motivo.'
            );
        }
    }

    /** @throws OrderEditError */
    public static function ensureCantidad(int $cantidad): void
    {
        if ($cantidad < 1) {
            throw new OrderEditError('La cantidad tiene que ser al menos 1. Para dejarlo en cero, quita la linea.');
        }
    }

    /**
     * El pedido no puede terminar valiendo menos de lo que ya se cobro.
     *
     * @param int $netoCobradoCents lo cobrado menos lo reembolsado
     * @throws OrderEditError
     */
    public static function ensureCubreLoCobrado(int $nuevoTotalCents, int $netoCobradoCents): void
    {
        if ($netoCobradoCents > 0 && $nuevoTotalCents < $netoCobradoCents) {
            throw new OrderEditError(sprintf(
                'El pedido quedaria en %s y ya se cobraron %s. Reembolsa la diferencia antes de quitarlo.',
                Money::toDecimalString($nuevoTotalCents),
                Money::toDecimalString($netoCobradoCents),
            ));
        }
    }
}
