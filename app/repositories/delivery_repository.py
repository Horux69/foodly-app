import uuid
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.orm import Session, joinedload

from app.models.branch import Branch
from app.models.delivery import DeliveryInfo, DeliveryZone


def list_zones(db: Session, branch_id: uuid.UUID) -> list[DeliveryZone]:
    stmt = select(DeliveryZone).where(DeliveryZone.branch_id == branch_id).order_by(DeliveryZone.name)
    return list(db.scalars(stmt))


def get_zone(db: Session, tenant_id: uuid.UUID, zone_id: uuid.UUID) -> DeliveryZone | None:
    """Se sube hasta la sucursal para no aceptar una zona de otra empresa."""
    stmt = (
        select(DeliveryZone)
        .join(Branch, DeliveryZone.branch_id == Branch.id)
        .where(DeliveryZone.id == zone_id, Branch.tenant_id == tenant_id)
    )
    return db.scalars(stmt).first()


def get_zone_by_name(db: Session, branch_id: uuid.UUID, name: str) -> DeliveryZone | None:
    stmt = select(DeliveryZone).where(DeliveryZone.branch_id == branch_id, DeliveryZone.name == name)
    return db.scalars(stmt).first()


def create_zone(
    db: Session,
    *,
    branch_id: uuid.UUID,
    name: str,
    fee: Decimal,
    min_order: Decimal,
    est_minutes: int | None,
) -> DeliveryZone:
    zone = DeliveryZone(
        branch_id=branch_id, name=name, fee=fee, min_order=min_order, est_minutes=est_minutes
    )
    db.add(zone)
    db.flush()
    return zone


def get_for_order(db: Session, order_id: uuid.UUID) -> DeliveryInfo | None:
    stmt = (
        select(DeliveryInfo)
        .options(joinedload(DeliveryInfo.zone), joinedload(DeliveryInfo.courier))
        .where(DeliveryInfo.order_id == order_id)
    )
    return db.scalars(stmt).first()


def create_info(
    db: Session,
    *,
    order_id: uuid.UUID,
    address: str,
    zone_id: uuid.UUID | None,
    lat: Decimal | None = None,
    lng: Decimal | None = None,
) -> DeliveryInfo:
    info = DeliveryInfo(order_id=order_id, address=address, zone_id=zone_id, lat=lat, lng=lng)
    db.add(info)
    db.flush()
    return info
