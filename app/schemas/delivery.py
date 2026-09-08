import uuid
from datetime import datetime
from decimal import Decimal

from pydantic import BaseModel, Field


class DeliveryZoneCreate(BaseModel):
    name: str = Field(min_length=1, max_length=100)
    fee: Decimal = Field(ge=0)
    min_order: Decimal = Field(default=Decimal("0"), ge=0)
    est_minutes: int | None = Field(default=None, gt=0)


class DeliveryZoneOut(BaseModel):
    id: uuid.UUID
    branch_id: uuid.UUID
    name: str
    fee: Decimal
    min_order: Decimal
    est_minutes: int | None
    is_active: bool

    model_config = {"from_attributes": True}


class DeliveryInput(BaseModel):
    """Datos de entrega de un pedido.

    Si viene `zone_id`, la tarifa la pone la zona y se ignora cualquier
    `delivery_fee` del cuerpo: el precio del reparto lo fija el restaurante.
    """

    address: str = Field(min_length=1, max_length=255)
    zone_id: uuid.UUID | None = None
    lat: Decimal | None = None
    lng: Decimal | None = None


class CourierAssign(BaseModel):
    courier_id: uuid.UUID


class EstimatedTimeUpdate(BaseModel):
    minutes: int = Field(gt=0, le=600)


class DeliveryInfoOut(BaseModel):
    order_id: uuid.UUID
    address: str
    zone_id: uuid.UUID | None
    zone_name: str | None
    courier_id: uuid.UUID | None
    courier_name: str | None
    estimated_time: datetime | None
    dispatched_at: datetime | None
    delivered_at: datetime | None
