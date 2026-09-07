"""Calculo de totales de un pedido.

Funcion pura y testeable: no toca base de datos ni framework.
Es la UNICA fuente de verdad para los totales; nunca duplicar
esta aritmetica en controladores o en el frontend.
"""

from dataclasses import dataclass, field
from decimal import Decimal, ROUND_HALF_UP

TWO = Decimal("0.01")


def _round(value: Decimal) -> Decimal:
    return value.quantize(TWO, rounding=ROUND_HALF_UP)


@dataclass
class LineInput:
    quantity: int
    unit_price: Decimal
    tax_rate: Decimal = Decimal("0")
    tax_included_in_price: bool = True
    modifier_deltas: list[Decimal] = field(default_factory=list)


@dataclass
class LineResult:
    line_total: Decimal
    tax_amount: Decimal


@dataclass
class OrderTotals:
    subtotal: Decimal
    tax_total: Decimal
    delivery_fee: Decimal
    discount: Decimal
    tip: Decimal
    total: Decimal
    lines: list[LineResult]


def compute_line(line: LineInput) -> LineResult:
    unit = line.unit_price + sum(line.modifier_deltas, Decimal("0"))
    gross = _round(unit * line.quantity)

    if line.tax_rate == 0:
        return LineResult(line_total=gross, tax_amount=Decimal("0.00"))

    if line.tax_included_in_price:
        # El precio ya trae el impuesto: se extrae para reportarlo.
        base = gross / (Decimal("1") + line.tax_rate)
        tax = _round(gross - base)
    else:
        tax = _round(gross * line.tax_rate)
        gross = _round(gross + tax)

    return LineResult(line_total=gross, tax_amount=tax)


def compute_totals(
    lines: list[LineInput],
    *,
    delivery_fee: Decimal = Decimal("0"),
    discount: Decimal = Decimal("0"),
    tip: Decimal = Decimal("0"),
) -> OrderTotals:
    results = [compute_line(line) for line in lines]
    subtotal = _round(sum((r.line_total for r in results), Decimal("0")))
    tax_total = _round(sum((r.tax_amount for r in results), Decimal("0")))
    total = _round(subtotal + delivery_fee - discount + tip)

    if total < 0:
        raise ValueError("El total del pedido no puede ser negativo")

    return OrderTotals(
        subtotal=subtotal,
        tax_total=tax_total,
        delivery_fee=_round(delivery_fee),
        discount=_round(discount),
        tip=_round(tip),
        total=total,
        lines=results,
    )
