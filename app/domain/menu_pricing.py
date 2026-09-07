"""Precio y disponibilidad efectivos de un producto de menu.

Cascada unica (principio no negociable): `branch_menu_overrides` manda si
existe, si no se usa `menu_items`. Ningun otro lugar del codigo debe repetir
esta resolucion.
"""

from dataclasses import dataclass
from decimal import Decimal


@dataclass(frozen=True)
class EffectiveMenuItem:
    price: Decimal
    is_available: bool


def resolve_effective_menu_item(
    *,
    base_price: Decimal,
    base_is_available: bool,
    override_price: Decimal | None = None,
    override_is_available: bool | None = None,
) -> EffectiveMenuItem:
    price = override_price if override_price is not None else base_price
    is_available = override_is_available if override_is_available is not None else base_is_available
    return EffectiveMenuItem(price=price, is_available=is_available)
