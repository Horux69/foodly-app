from decimal import Decimal

from app.domain.payment_balance import compute_balance


def test_sin_pagos_queda_todo_pendiente():
    balance = compute_balance(order_total=Decimal("62000"), paid_amounts=[])
    assert balance.paid == Decimal("0.00")
    assert balance.pending == Decimal("62000.00")
    assert balance.is_settled is False


def test_pago_parcial_no_salda():
    balance = compute_balance(order_total=Decimal("62000"), paid_amounts=[Decimal("20000")])
    assert balance.pending == Decimal("42000.00")
    assert balance.is_settled is False


def test_pagos_divididos_que_suman_el_total_saldan():
    balance = compute_balance(
        order_total=Decimal("62000"), paid_amounts=[Decimal("20000"), Decimal("30000"), Decimal("12000")]
    )
    assert balance.pending == Decimal("0.00")
    assert balance.is_settled is True


def test_pago_de_mas_queda_saldado_con_pendiente_negativo():
    # Caso real de caja: se recibe de mas y el vuelto se maneja fuera del sistema.
    balance = compute_balance(order_total=Decimal("62000"), paid_amounts=[Decimal("70000")])
    assert balance.pending == Decimal("-8000.00")
    assert balance.is_settled is True
