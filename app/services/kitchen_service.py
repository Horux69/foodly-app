"""Tablero de cocina (KDS).

Se apoya en `order_statuses.category`, nunca en el `code`: asi funciona igual
aunque cada restaurante nombre sus estados distinto.
"""

import uuid
from dataclasses import dataclass

from sqlalchemy.orm import Session

from app.models.order import Order
from app.models.order_status import OrderStatus
from app.repositories import order_repository
from app.services.order_status_service import build_machine

KDS_CATEGORIES = ["new", "kitchen", "ready"]


@dataclass(frozen=True)
class KitchenOrder:
    order: Order
    next_statuses: list[OrderStatus]


def get_board(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> list[KitchenOrder]:
    orders = order_repository.list_by_status_categories(db, tenant_id, branch_id, KDS_CATEGORIES)
    machine, statuses_by_id = build_machine(db, tenant_id)
    return [
        KitchenOrder(
            order=order,
            next_statuses=[statuses_by_id[s.id] for s in machine.allowed_from(str(order.status_id))],
        )
        for order in orders
    ]
