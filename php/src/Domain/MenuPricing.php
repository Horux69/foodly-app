<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Precio y disponibilidad efectivos de un producto de menu.
 *
 * Cascada unica (principio no negociable): branch_menu_overrides manda si
 * existe, si no se usa menu_items. Ningun otro lugar del codigo debe repetir
 * esta resolucion.
 */
final class MenuPricing
{
    public static function resolveEffectiveMenuItem(
        int $basePriceCents,
        bool $baseIsAvailable,
        ?int $overridePriceCents = null,
        ?bool $overrideIsAvailable = null,
    ): EffectiveMenuItem {
        return new EffectiveMenuItem(
            $overridePriceCents ?? $basePriceCents,
            $overrideIsAvailable ?? $baseIsAvailable,
        );
    }
}
