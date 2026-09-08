import uuid

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.customer import Customer
from app.models.order import Order


def get_or_create_by_phone(
    db: Session, *, tenant_id: uuid.UUID, phone: str, name: str | None = None
) -> Customer:
    stmt = select(Customer).where(Customer.tenant_id == tenant_id, Customer.phone == phone)
    customer = db.scalars(stmt).first()
    if customer is not None:
        return customer

    customer = Customer(tenant_id=tenant_id, phone=phone, name=name)
    db.add(customer)
    db.flush()
    return customer


def get(db: Session, tenant_id: uuid.UUID, customer_id: uuid.UUID) -> Customer | None:
    stmt = select(Customer).where(Customer.tenant_id == tenant_id, Customer.id == customer_id)
    return db.scalars(stmt).first()


def search(db: Session, tenant_id: uuid.UUID, term: str | None, limit: int = 50) -> list[Customer]:
    """Busca por teléfono o nombre. El teléfono es la llave real: es lo que
    permitirá al futuro agente de WhatsApp reconocer a quien escribe."""
    stmt = select(Customer).where(Customer.tenant_id == tenant_id)
    if term:
        patron = f"%{term.lower()}%"
        stmt = stmt.where(
            func.lower(Customer.phone).like(patron) | func.lower(Customer.name).like(patron)
        )
    return list(db.scalars(stmt.order_by(Customer.created_at.desc()).limit(limit)))


def orders_of(db: Session, tenant_id: uuid.UUID, customer_id: uuid.UUID, limit: int = 20) -> list[Order]:
    stmt = (
        select(Order)
        .where(Order.tenant_id == tenant_id, Order.customer_id == customer_id)
        .order_by(Order.created_at.desc())
        .limit(limit)
    )
    return list(db.scalars(stmt))


def stats_of(db: Session, tenant_id: uuid.UUID, customer_id: uuid.UUID) -> dict:
    """Cuánto ha pedido y cuánto ha gastado, contando solo lo completado."""
    from app.models.order_status import OrderStatus

    stmt = (
        select(func.count(Order.id), func.coalesce(func.sum(Order.total), 0))
        .join(OrderStatus, Order.status_id == OrderStatus.id)
        .where(
            Order.tenant_id == tenant_id,
            Order.customer_id == customer_id,
            OrderStatus.category == "completed",
        )
    )
    pedidos, total = db.execute(stmt).one()
    return {"orders": pedidos, "spent": total}
