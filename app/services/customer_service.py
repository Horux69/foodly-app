"""Clientes.

La base de clientes se llena sola: al tomar un pedido con teléfono, el
cliente queda registrado. Este módulo la vuelve consultable, que es el paso
previo a que el agente de WhatsApp reconozca a quien escribe.
"""

import uuid

from sqlalchemy.orm import Session

from app.models.customer import Customer
from app.models.order import Order
from app.repositories import customer_repository


class CustomerError(Exception):
    pass


def search(db: Session, *, tenant_id: uuid.UUID, term: str | None = None) -> list[Customer]:
    return customer_repository.search(db, tenant_id, term)


def detail(
    db: Session, *, tenant_id: uuid.UUID, customer_id: uuid.UUID
) -> tuple[Customer, list[Order], dict]:
    customer = customer_repository.get(db, tenant_id, customer_id)
    if customer is None:
        raise CustomerError("El cliente no existe para este tenant")

    return (
        customer,
        customer_repository.orders_of(db, tenant_id, customer.id),
        customer_repository.stats_of(db, tenant_id, customer.id),
    )


def update(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    customer_id: uuid.UUID,
    name: str | None = None,
    email: str | None = None,
) -> Customer:
    """Se puede corregir el nombre y el correo, no el teléfono.

    El teléfono es la identidad del cliente y la llave única por empresa;
    cambiarlo convertiría a alguien en otra persona en vez de corregir un
    dato. Para eso se registra un cliente nuevo.
    """
    customer = customer_repository.get(db, tenant_id, customer_id)
    if customer is None:
        raise CustomerError("El cliente no existe para este tenant")

    if name is not None:
        customer.name = name
    if email is not None:
        customer.email = email

    db.commit()
    db.refresh(customer)
    return customer
