"""Avance de estado de un pedido.

Valida contra la maquina de estados que cada tenant configuro (nunca contra
codigos hardcodeados) y exige el permiso que declara cada transicion. Cocina
y caja usan este mismo camino: lo unico que cambia es el permiso que pide la
transicion configurada.
"""

import uuid
from datetime import datetime, timezone

from sqlalchemy.orm import Session

from app.domain.status_machine import Status, StatusMachine, Transition, TransitionError
from app.models.order import Order
from app.models.order_status import OrderStatus
from app.repositories import delivery_repository, order_repository, order_status_repository
from app.services.payment_service import get_balance_for_order


class OrderStatusError(Exception):
    pass


def build_machine(db: Session, tenant_id: uuid.UUID) -> tuple[StatusMachine, dict[str, OrderStatus]]:
    statuses = order_status_repository.list_statuses(db, tenant_id)
    transitions = order_status_repository.list_transitions(db, tenant_id)
    machine = StatusMachine(
        statuses=[
            Status(id=str(s.id), code=s.code, category=s.category, is_initial=s.is_initial, is_final=s.is_final)
            for s in statuses
        ],
        transitions=[
            Transition(
                from_status_id=str(t.from_status_id),
                to_status_id=str(t.to_status_id),
                required_permission=t.required_permission,
            )
            for t in transitions
        ],
    )
    return machine, {str(s.id): s for s in statuses}


def allowed_next_statuses(db: Session, *, tenant_id: uuid.UUID, order: Order) -> list[OrderStatus]:
    machine, statuses_by_id = build_machine(db, tenant_id)
    return [statuses_by_id[s.id] for s in machine.allowed_from(str(order.status_id))]


def _marcar_entrega(db: Session, order: Order, categoria: str) -> None:
    """Anota cuándo salió y cuándo llegó un domicilio.

    Se guía por la categoría del estado y no por su código: un restaurante
    puede llamar 'En moto' a lo que otro llama 'En camino', y ambos son
    'in_transit'. Los pedidos sin domicilio no tienen nada que anotar.
    """
    if categoria not in ("in_transit", "completed"):
        return

    info = delivery_repository.get_for_order(db, order.id)
    if info is None:
        return

    ahora = datetime.now(timezone.utc)
    if categoria == "in_transit" and info.dispatched_at is None:
        info.dispatched_at = ahora
    elif categoria == "completed" and info.delivered_at is None:
        info.delivered_at = ahora


def advance_status(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    order_id: uuid.UUID,
    to_status_id: uuid.UUID,
    permissions: list[str],
    changed_by: uuid.UUID | None,
    note: str | None = None,
) -> Order:
    order = order_repository.get_by_id(db, tenant_id, order_id)
    if order is None:
        raise OrderStatusError("Pedido no encontrado")

    machine, statuses_by_id = build_machine(db, tenant_id)
    target = statuses_by_id.get(str(to_status_id))
    if target is None:
        raise OrderStatusError("El estado no existe para este tenant")

    try:
        machine.validate(str(order.status_id), str(to_status_id), permissions)
    except TransitionError as exc:
        raise OrderStatusError(str(exc)) from exc

    # Regla de caja: un pedido no se da por completado sin estar saldado.
    # Se apoya en la categoria, no en el nombre del estado, y deja fuera
    # 'cancelled' (tambien final) porque un pedido sin pagar si se cancela.
    if target.category == "completed":
        balance = get_balance_for_order(db, order)
        if not balance.is_settled:
            raise OrderStatusError(f"El pedido no está saldado: faltan {balance.pending}")

    order.status_id = target.id
    order.updated_at = datetime.now(timezone.utc)
    _marcar_entrega(db, order, target.category)
    order_repository.add_status_history(
        db, order_id=order.id, status_id=target.id, changed_by=changed_by, note=note
    )

    db.commit()
    db.refresh(order)
    return order
