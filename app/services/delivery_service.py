"""Domicilios: zonas de reparto y asignación de repartidor."""

import uuid
from datetime import datetime, timedelta, timezone
from decimal import Decimal

from sqlalchemy.orm import Session

from app.models.delivery import DeliveryInfo, DeliveryZone
from app.repositories import branch_repository, delivery_repository, order_repository, user_repository


class DeliveryServiceError(Exception):
    pass


def _sucursal_propia(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID):
    branch = branch_repository.get(db, tenant_id, branch_id)
    if branch is None:
        raise DeliveryServiceError("La sucursal no existe para este tenant")
    return branch


def list_zones(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> list[DeliveryZone]:
    branch = _sucursal_propia(db, tenant_id, branch_id)
    return delivery_repository.list_zones(db, branch.id)


def create_zone(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID,
    name: str,
    fee: Decimal,
    min_order: Decimal,
    est_minutes: int | None = None,
) -> DeliveryZone:
    branch = _sucursal_propia(db, tenant_id, branch_id)

    if delivery_repository.get_zone_by_name(db, branch.id, name) is not None:
        raise DeliveryServiceError(f"Ya existe una zona llamada '{name}' en esta sucursal")

    zone = delivery_repository.create_zone(
        db, branch_id=branch.id, name=name, fee=fee, min_order=min_order, est_minutes=est_minutes
    )
    db.commit()
    db.refresh(zone)
    return zone


def set_zone_active(
    db: Session, *, tenant_id: uuid.UUID, zone_id: uuid.UUID, is_active: bool
) -> DeliveryZone:
    zone = delivery_repository.get_zone(db, tenant_id, zone_id)
    if zone is None:
        raise DeliveryServiceError("La zona no existe para este tenant")
    zone.is_active = is_active
    db.commit()
    db.refresh(zone)
    return zone


def get_delivery(db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID) -> DeliveryInfo:
    order = order_repository.get_by_id(db, tenant_id, order_id)
    if order is None:
        raise DeliveryServiceError("Pedido no encontrado")

    info = delivery_repository.get_for_order(db, order.id)
    if info is None:
        raise DeliveryServiceError("Este pedido no es un domicilio")
    return info


def assign_courier(
    db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID, courier_id: uuid.UUID
) -> DeliveryInfo:
    """Asigna un repartidor. No marca despachado: eso lo hace el avance de
    estado, para que la hora de salida sea una sola y venga del mismo lugar."""
    info = get_delivery(db, tenant_id=tenant_id, order_id=order_id)

    courier = user_repository.get(db, tenant_id, courier_id)
    if courier is None:
        raise DeliveryServiceError("El repartidor no existe para este tenant")
    if not courier.is_active:
        raise DeliveryServiceError(f"{courier.name} está inactivo")

    info.courier_id = courier.id
    db.commit()
    db.refresh(info)
    return info


def set_estimated_time(
    db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID, minutes: int
) -> DeliveryInfo:
    info = get_delivery(db, tenant_id=tenant_id, order_id=order_id)
    info.estimated_time = datetime.now(timezone.utc) + timedelta(minutes=minutes)
    db.commit()
    db.refresh(info)
    return info
