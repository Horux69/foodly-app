from decimal import Decimal

import pytest

from app.domain.order_totals import LineInput, compute_totals


def test_subtotal_con_modificadores():
    lines = [
        LineInput(quantity=2, unit_price=Decimal("18000"),
                  modifier_deltas=[Decimal("2000")]),
    ]
    totals = compute_totals(lines)
    assert totals.subtotal == Decimal("40000.00")


def test_impuesto_incluido_en_precio():
    lines = [LineInput(quantity=1, unit_price=Decimal("10800"),
                       tax_rate=Decimal("0.08"), tax_included_in_price=True)]
    totals = compute_totals(lines)
    assert totals.subtotal == Decimal("10800.00")
    assert totals.tax_total == Decimal("800.00")


def test_impuesto_agregado_al_precio():
    lines = [LineInput(quantity=1, unit_price=Decimal("10000"),
                       tax_rate=Decimal("0.08"), tax_included_in_price=False)]
    totals = compute_totals(lines)
    assert totals.tax_total == Decimal("800.00")
    assert totals.subtotal == Decimal("10800.00")


def test_domicilio_descuento_y_propina():
    lines = [LineInput(quantity=1, unit_price=Decimal("20000"))]
    totals = compute_totals(
        lines,
        delivery_fee=Decimal("5000"),
        discount=Decimal("2000"),
        tip=Decimal("1000"),
    )
    assert totals.total == Decimal("24000.00")


def test_total_negativo_falla():
    lines = [LineInput(quantity=1, unit_price=Decimal("5000"))]
    with pytest.raises(ValueError):
        compute_totals(lines, discount=Decimal("10000"))
