from decimal import Decimal

import pytest

from app.domain.delivery import DeliveryError, DeliveryZoneRules, validate_minimum

NORTE = DeliveryZoneRules(
    name="Norte", fee=Decimal("5000"), min_order=Decimal("20000"), est_minutes=30
)
SIN_MINIMO = DeliveryZoneRules(name="Centro", fee=Decimal("3000"), min_order=Decimal("0"))


def test_subtotal_suficiente_pasa():
    validate_minimum(subtotal=Decimal("25000"), zone=NORTE)


def test_subtotal_exacto_pasa():
    validate_minimum(subtotal=Decimal("20000"), zone=NORTE)


def test_subtotal_insuficiente_falla_y_dice_cuanto_falta():
    with pytest.raises(DeliveryError) as error:
        validate_minimum(subtotal=Decimal("18000"), zone=NORTE)
    assert "faltan 2000" in str(error.value)


def test_una_zona_sin_minimo_acepta_cualquier_pedido():
    validate_minimum(subtotal=Decimal("1000"), zone=SIN_MINIMO)


def test_el_domicilio_no_cuenta_para_alcanzar_el_minimo():
    """18000 de comida + 5000 de domicilio suman 23000, pero el minimo mira
    solo la comida."""
    with pytest.raises(DeliveryError):
        validate_minimum(subtotal=Decimal("18000"), zone=NORTE)
