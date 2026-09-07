"""Interfaz de cobro.

Los metodos presenciales (efectivo, datafono, transferencia) se confirman en
el acto: el dinero ya se recibio y el sistema solo lo asienta. Una pasarela
real (WhatsApp Pay, PSE) implementa esta misma interfaz y devuelve `pending`
hasta que su webhook confirme, sin que caja ni pedidos cambien.
"""

from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal
from typing import Protocol


@dataclass(frozen=True)
class ChargeResult:
    status: str  # 'paid' | 'pending' | 'failed'
    external_reference: str | None
    paid_at: datetime | None


class PaymentProvider(Protocol):
    code: str

    def charge(self, *, amount: Decimal, reference: str | None) -> ChargeResult: ...


class ManualProvider:
    """Cobro presencial: la plata se recibe fuera del sistema y aqui queda registrada."""

    def __init__(self, code: str) -> None:
        self.code = code

    def charge(self, *, amount: Decimal, reference: str | None) -> ChargeResult:
        return ChargeResult(status="paid", external_reference=reference, paid_at=datetime.now(timezone.utc))


_PROVIDERS: dict[str, PaymentProvider] = {
    "cash": ManualProvider("cash"),
    "card": ManualProvider("card"),
    "transfer": ManualProvider("transfer"),
}


def get_provider(method: str) -> PaymentProvider | None:
    return _PROVIDERS.get(method)


def available_methods() -> list[str]:
    return list(_PROVIDERS)
