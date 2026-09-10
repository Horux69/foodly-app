<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Que un grupo de modificadores se pueda cumplir.
 *
 * ModifierValidation responde la otra pregunta —si una seleccion concreta
 * respeta el grupo— y esta responde la de antes: si el grupo, tal como esta
 * configurado, se puede satisfacer siquiera. La base solo exige
 * `max_select >= min_select`, que deja pasar configuraciones que congelan la
 * venta: un grupo obligatorio con `max_select = 0` hace que ningun pedido con
 * ese producto se pueda crear, y el error saldria en el mostrador y no aqui.
 */
final class ModifierGroupRules
{
    public const NOMBRE_MAX = 100;

    public static function validate(string $name, int $minSelect, int $maxSelect, bool $isRequired): void
    {
        if (trim($name) === '') {
            throw new ModifierGroupError('El grupo necesita un nombre');
        }
        if (mb_strlen($name) > self::NOMBRE_MAX) {
            throw new ModifierGroupError('El nombre del grupo no puede pasar de ' . self::NOMBRE_MAX . ' caracteres');
        }
        if ($minSelect < 0) {
            throw new ModifierGroupError('El minimo no puede ser negativo');
        }
        if ($maxSelect < 1) {
            throw new ModifierGroupError('El maximo debe ser al menos 1: un grupo donde no se puede elegir nada no sirve de nada');
        }
        if ($minSelect > $maxSelect) {
            throw new ModifierGroupError("El minimo ({$minSelect}) no puede ser mayor que el maximo ({$maxSelect})");
        }

        // Un grupo obligatorio con minimo 0 se contradice: ModifierValidation
        // ya exige al menos una seleccion, asi que el 0 guardado mentiria
        // sobre lo que el grupo pide.
        if ($isRequired && $minSelect < 1) {
            throw new ModifierGroupError('Un grupo obligatorio necesita un minimo de al menos 1');
        }
    }

    /**
     * Como se lee la regla en pantalla. Vive aqui y no en el navegador para
     * que la web y cualquier otro cliente digan lo mismo.
     */
    public static function describe(int $minSelect, int $maxSelect, bool $isRequired): string
    {
        if ($minSelect === $maxSelect) {
            return $minSelect === 1 ? 'Elige 1' : "Elige exactamente {$minSelect}";
        }
        if ($minSelect === 0) {
            return $maxSelect === 1 ? 'Opcional, hasta 1' : "Opcional, hasta {$maxSelect}";
        }
        return ($isRequired ? 'Obligatorio: elige' : 'Elige') . " entre {$minSelect} y {$maxSelect}";
    }
}
