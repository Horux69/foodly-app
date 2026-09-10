<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Quien atiende la cuenta.
 *
 * `created_by` responde "quien tecleo el pedido", que en un restaurante de
 * mesa no es siempre quien lo atiende: la tableta del pasillo la usan todos,
 * y en muchos sitios el cajero digita lo que el mesero le dicta. Sin
 * separarlos no se puede repartir la propina ni decir cuanto vendio cada
 * quien, que es para lo que se lleva la cuenta por mesero.
 *
 * Dos reglas, y las dos son sobre el momento:
 *
 * - **Se cambia mientras la cuenta siga viva.** Que un mesero tome la mesa
 *   de otro a mitad de servicio es normal —un cambio de turno, un descanso—,
 *   asi que la ventana es mas ancha que la de editar los productos: sigue
 *   abierta con el pedido listo o en camino.
 * - **Una vez cerrada, no.** Cambiar el mesero de un pedido ya entregado o
 *   anulado reescribiria un reporte que quizas ya se pago: la propina de ese
 *   turno se repartio con un nombre y aparecerria con otro. Lo que hay que
 *   corregir despues del cierre se corrige donde se reparte la plata, no
 *   reescribiendo la venta.
 */
final class ServerAssignment
{
    /**
     * Categorias en las que la cuenta ya esta cerrada.
     *
     * Por categoria y nunca por codigo (principio 6): cada restaurante
     * bautiza sus estados como quiere.
     */
    public const CERRADAS = ['completed', 'cancelled'];

    private function __construct()
    {
    }

    public static function esAsignable(string $categoria): bool
    {
        return !in_array($categoria, self::CERRADAS, true);
    }

    /** @throws ServerAssignmentError */
    public static function ensureAsignable(string $categoria): void
    {
        if (!self::esAsignable($categoria)) {
            throw new ServerAssignmentError(
                'Esta cuenta ya esta cerrada: el mesero a cargo no se cambia despues de entregarla o anularla'
            );
        }
    }

    /**
     * La nota que queda en la bitacora del pedido.
     *
     * Con el nombre anterior cuando lo habia: "Mesero: Ana" no dice nada si
     * la mesa era de Luis hasta hace un minuto, y quien revisa el reparto de
     * la propina necesita justamente eso.
     */
    public static function nota(?string $anterior, ?string $nuevo): string
    {
        if ($nuevo === null) {
            return $anterior === null ? 'Cuenta sin mesero' : "Se quito a {$anterior} de la cuenta";
        }
        return $anterior === null || $anterior === $nuevo
            ? "Mesero: {$nuevo}"
            : "Mesero cambiado de {$anterior} a {$nuevo}";
    }
}
