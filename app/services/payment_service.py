import uuid
from decimal import Decimal

from sqlalchemy.orm import Session

from app.domain.payment_balance import PaymentBalance, compute_balance
from app.models.order import Order
from app.models.payment import Payment
from app.repositories import order_repository, payment_repository
from app.services.payment_providers import available_methods, get_provider


class PaymentError(Exception):
    pass


def get_balance_for_order(db: Session, order: Order) -> PaymentBalance:
    payments = payment_repository.list_for_order(db, order.id)
    return compute_balance(
        order_total=order.total, paid_amounts=[p.amount for p in payments if p.status == "paid"]
    )


def get_balance(db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID) -> PaymentBalance:
    order = order_repository.get_by_id(db, tenant_id, order_id)
    if order is None:
        raise PaymentError("Pedido no encontrado")
    return get_balance_for_order(db, order)


def list_payments(db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID) -> list[Payment]:
    order = order_repository.get_by_id(db, tenant_id, order_id)
    if order is None:
        raise PaymentError("Pedido no encontrado")
    return payment_repository.list_for_order(db, order.id)


def register_payment(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    order_id: uuid.UUID,
    method: str,
    amount: Decimal,
    external_reference: str | None = None,
    idempotency_key: str | None = None,
) -> Payment:
    order = order_repository.get_by_id(db, tenant_id, order_id)
    if order is None:
        raise PaymentError("Pedido no encontrado")

    if idempotency_key:
        existing = payment_repository.get_by_idempotency_key(db, order.id, idempotency_key)
        if existing is not None:
            return existing

    provider = get_provider(method)
    if provider is None:
        raise PaymentError(f"Metodo de pago '{method}' no soportado. Disponibles: {available_methods()}")

    result = provider.charge(amount=amount, reference=external_reference)
    payment = payment_repository.create(
        db,
        order_id=order.id,
        method=method,
        status=result.status,
        amount=amount,
        external_reference=result.external_reference,
        idempotency_key=idempotency_key,
        paid_at=result.paid_at,
    )
    db.commit()
    db.refresh(payment)
    return payment
