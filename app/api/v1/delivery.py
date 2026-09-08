import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.models.delivery import DeliveryInfo
from app.schemas.admin import ActiveUpdate
from app.schemas.delivery import (
    CourierAssign,
    DeliveryInfoOut,
    DeliveryZoneCreate,
    DeliveryZoneOut,
    EstimatedTimeUpdate,
)
from app.services import delivery_service
from app.services.delivery_service import DeliveryServiceError

router = APIRouter()


def _salida(info: DeliveryInfo) -> DeliveryInfoOut:
    return DeliveryInfoOut(
        order_id=info.order_id,
        address=info.address,
        zone_id=info.zone_id,
        zone_name=info.zone.name if info.zone else None,
        courier_id=info.courier_id,
        courier_name=info.courier.name if info.courier else None,
        estimated_time=info.estimated_time,
        dispatched_at=info.dispatched_at,
        delivered_at=info.delivered_at,
    )


# ---------- Zonas ----------


@router.get("/branches/{branch_id}/delivery-zones", response_model=list[DeliveryZoneOut], tags=["domicilios"])
def list_zones_endpoint(
    branch_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("settings.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[DeliveryZoneOut]:
    try:
        zonas = delivery_service.list_zones(db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id)
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return [DeliveryZoneOut.model_validate(z) for z in zonas]


@router.post(
    "/branches/{branch_id}/delivery-zones",
    response_model=DeliveryZoneOut,
    status_code=status.HTTP_201_CREATED,
    tags=["domicilios"],
)
def create_zone_endpoint(
    branch_id: uuid.UUID,
    payload: DeliveryZoneCreate,
    ctx: Annotated[RequestContext, Depends(require("branches.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> DeliveryZoneOut:
    try:
        zona = delivery_service.create_zone(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=branch_id,
            name=payload.name,
            fee=payload.fee,
            min_order=payload.min_order,
            est_minutes=payload.est_minutes,
        )
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return DeliveryZoneOut.model_validate(zona)


@router.patch("/delivery-zones/{zone_id}/active", response_model=DeliveryZoneOut, tags=["domicilios"])
def set_zone_active_endpoint(
    zone_id: uuid.UUID,
    payload: ActiveUpdate,
    ctx: Annotated[RequestContext, Depends(require("branches.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> DeliveryZoneOut:
    try:
        zona = delivery_service.set_zone_active(
            db, tenant_id=uuid.UUID(ctx.tenant_id), zone_id=zone_id, is_active=payload.is_active
        )
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return DeliveryZoneOut.model_validate(zona)


# ---------- Entrega de un pedido ----------


@router.get("/orders/{order_id}/delivery", response_model=DeliveryInfoOut, tags=["domicilios"])
def get_delivery_endpoint(
    order_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> DeliveryInfoOut:
    try:
        info = delivery_service.get_delivery(db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id)
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return _salida(info)


@router.put("/orders/{order_id}/delivery/courier", response_model=DeliveryInfoOut, tags=["domicilios"])
def assign_courier_endpoint(
    order_id: uuid.UUID,
    payload: CourierAssign,
    ctx: Annotated[RequestContext, Depends(require("delivery.assign"))],
    db: Annotated[Session, Depends(get_db)],
) -> DeliveryInfoOut:
    try:
        info = delivery_service.assign_courier(
            db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id, courier_id=payload.courier_id
        )
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _salida(info)


@router.put("/orders/{order_id}/delivery/eta", response_model=DeliveryInfoOut, tags=["domicilios"])
def set_eta_endpoint(
    order_id: uuid.UUID,
    payload: EstimatedTimeUpdate,
    ctx: Annotated[RequestContext, Depends(require("delivery.assign"))],
    db: Annotated[Session, Depends(get_db)],
) -> DeliveryInfoOut:
    try:
        info = delivery_service.set_estimated_time(
            db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id, minutes=payload.minutes
        )
    except DeliveryServiceError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return _salida(info)
