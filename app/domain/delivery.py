"""Reglas de domicilio.

Puras y testeables: reciben la zona ya resuelta y deciden si el pedido se
puede despachar y cuanto cuesta llevarlo.
"""

from dataclasses import dataclass
from decimal import Decimal


@dataclass(frozen=True)
class DeliveryZoneRules:
    name: str
    fee: Decimal
    min_order: Decimal
    est_minutes: int | None = None


class DeliveryError(Exception):
    pass


def validate_minimum(*, subtotal: Decimal, zone: DeliveryZoneRules) -> None:
    """El minimo se mide contra el subtotal, no contra el total.

    Contar el domicilio para alcanzar el minimo seria hacer trampa: la zona
    pide un consumo minimo de comida, no de plata facturada.
    """
    if subtotal < zone.min_order:
        faltante = zone.min_order - subtotal
        raise DeliveryError(
            f"La zona '{zone.name}' pide un mínimo de {zone.min_order}: faltan {faltante}"
        )
