import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.customer import Customer


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
