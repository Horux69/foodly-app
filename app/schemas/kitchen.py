import uuid
from datetime import datetime

from pydantic import BaseModel

from app.schemas.order import OrderStatusOut


class KitchenItemOut(BaseModel):
    name_snapshot: str
    quantity: int
    notes: str | None
    modifiers: list[str]


class KitchenOrderOut(BaseModel):
    id: uuid.UUID
    order_number: str
    channel: str
    table_code: str | None
    created_at: datetime
    status: OrderStatusOut
    items: list[KitchenItemOut]
    next_statuses: list[OrderStatusOut]
