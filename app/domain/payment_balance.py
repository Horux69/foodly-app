"""Saldo de un pedido frente a sus pagos.

Funcion pura: recibe el total del pedido y los montos ya cobrados, y dice
cuanto falta. Es la UNICA fuente de verdad para decidir si un pedido esta
saldado; no duplicar esta resta en controladores ni en el frontend.
"""

from dataclasses import dataclass
from decimal import ROUND_HALF_UP, Decimal

TWO = Decimal("0.01")


def _round(value: Decimal) -> Decimal:
    return value.quantize(TWO, rounding=ROUND_HALF_UP)


@dataclass(frozen=True)
class PaymentBalance:
    total: Decimal
    paid: Decimal
    pending: Decimal
    is_settled: bool


def compute_balance(*, order_total: Decimal, paid_amounts: list[Decimal]) -> PaymentBalance:
    total = _round(order_total)
    paid = _round(sum(paid_amounts, Decimal("0")))
    pending = _round(total - paid)
    return PaymentBalance(total=total, paid=paid, pending=pending, is_settled=paid >= total)
