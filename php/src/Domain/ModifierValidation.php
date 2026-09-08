<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Validacion de selecciones de modificadores contra su grupo.
 *
 * Pura y testeable: recibe la configuracion ya resuelta (min_select/max_select
 * por grupo) y cuantos modificadores de ese grupo trae la linea del pedido.
 */
final class ModifierValidation
{
    public static function validateSelection(ModifierGroupConstraint $constraint, int $selectedCount): void
    {
        if ($constraint->isRequired && $selectedCount === 0) {
            throw new ModifierValidationError("El grupo '{$constraint->name}' es obligatorio");
        }
        if ($selectedCount < $constraint->minSelect) {
            throw new ModifierValidationError(
                "El grupo '{$constraint->name}' requiere minimo {$constraint->minSelect} seleccion(es)"
            );
        }
        if ($selectedCount > $constraint->maxSelect) {
            throw new ModifierValidationError(
                "El grupo '{$constraint->name}' permite maximo {$constraint->maxSelect} seleccion(es)"
            );
        }
    }
}
