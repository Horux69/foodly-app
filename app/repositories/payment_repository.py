import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.payment import Payment


def list_for_order(db: Session, order_id: uuid.UUID) -> list[Payment]:
    stmt = select(Payment).where(Payment.order_id == order_id).order_by(Payment.created_at)
    return list(db.scalars(stmt))


def get_by_idempotency_key(db: Session, order_id: uuid.UUID, key: str) -> Payment | None:
    stmt = select(Payment).where(Payment.order_id == order_id, Payment.idempotency_key == key)
    return db.scalars(stmt).first()


def create(
    db: Session,
    *,
    order_id: uuid.UUID,
    method: str,
    status: str,
    amount: Decimal,
    external_reference: str | None,
    idempotency_key: str | None,
    paid_at: datetime | None,
) -> Payment:
    payment = Payment(
        order_id=order_id,
        method=method,
        status=status,
        amount=amount,
        external_reference=external_reference,
        idempotency_key=idempotency_key,
        paid_at=paid_at,
    )
    db.add(payment)
    db.flush()
    return payment
