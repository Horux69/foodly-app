import uuid
from decimal import Decimal

from pydantic import BaseModel, Field

from app.schemas.delivery import DeliveryInput


class OrderItemCreate(BaseModel):
    menu_item_id: uuid.UUID
    quantity: int = Field(gt=0)
    modifier_ids: list[uuid.UUID] = Field(default_factory=list)
    notes: str | None = None


class OrderCreate(BaseModel):
    channel: str
    items: list[OrderItemCreate] = Field(min_length=1)
    customer_phone: str | None = None
    customer_name: str | None = None
    table_code: str | None = None
    notes: str | None = None
    idempotency_key: str | None = None
    # Con datos de entrega el pedido es un domicilio. Si además trae zona, la
    # tarifa la pone la zona y este `delivery_fee` se ignora.
    delivery: DeliveryInput | None = None
    delivery_fee: Decimal = Decimal("0")
    discount: Decimal = Decimal("0")
    tip: Decimal = Decimal("0")


class OrderItemModifierOut(BaseModel):
    modifier_id: uuid.UUID
    name_snapshot: str
    price_delta: Decimal


class OrderItemOut(BaseModel):
    id: uuid.UUID
    menu_item_id: uuid.UUID
    name_snapshot: str
    quantity: int
    unit_price: Decimal
    tax_amount: Decimal
    line_total: Decimal
    notes: str | None
    modifiers: list[OrderItemModifierOut]


class OrderPreviewOut(BaseModel):
    subtotal: Decimal
    tax_total: Decimal
    delivery_fee: Decimal
    discount: Decimal
    tip: Decimal
    total: Decimal


class OrderStatusOut(BaseModel):
    id: uuid.UUID
    code: str
    name: str
    category: str
    color: str | None

    model_config = {"from_attributes": True}


class OrderStatusChange(BaseModel):
    to_status_id: uuid.UUID
    note: str | None = None


class OrderOut(BaseModel):
    id: uuid.UUID
    order_number: str
    channel: str
    subtotal: Decimal
    tax_total: Decimal
    delivery_fee: Decimal
    discount: Decimal
    tip: Decimal
    total: Decimal
    notes: str | None
    items: list[OrderItemOut]
