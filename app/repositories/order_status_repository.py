import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session, aliased

from app.models.order_status import OrderStatus, OrderStatusTransition


def get_initial(db: Session, tenant_id: uuid.UUID) -> OrderStatus | None:
    stmt = select(OrderStatus).where(OrderStatus.tenant_id == tenant_id, OrderStatus.is_initial.is_(True))
    return db.scalars(stmt).first()


def get(db: Session, tenant_id: uuid.UUID, status_id: uuid.UUID) -> OrderStatus | None:
    stmt = select(OrderStatus).where(OrderStatus.tenant_id == tenant_id, OrderStatus.id == status_id)
    return db.scalars(stmt).first()


def list_statuses(db: Session, tenant_id: uuid.UUID) -> list[OrderStatus]:
    stmt = select(OrderStatus).where(OrderStatus.tenant_id == tenant_id).order_by(OrderStatus.sort_order)
    return list(db.scalars(stmt))


def list_transitions(db: Session, tenant_id: uuid.UUID) -> list[OrderStatusTransition]:
    origin = aliased(OrderStatus)
    stmt = (
        select(OrderStatusTransition)
        .join(origin, OrderStatusTransition.from_status_id == origin.id)
        .where(origin.tenant_id == tenant_id)
    )
    return list(db.scalars(stmt))
