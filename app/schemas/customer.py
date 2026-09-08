import uuid
from datetime import datetime
from decimal import Decimal

from pydantic import BaseModel, Field


class CustomerOut(BaseModel):
    id: uuid.UUID
    phone: str
    name: str | None
    email: str | None
    created_at: datetime

    model_config = {"from_attributes": True}


class CustomerUpdate(BaseModel):
    """El teléfono no se edita: es la identidad del cliente."""

    name: str | None = Field(default=None, max_length=150)
    email: str | None = Field(default=None, max_length=150)


class CustomerOrderOut(BaseModel):
    id: uuid.UUID
    order_number: str
    channel: str
    total: Decimal
    created_at: datetime


class CustomerDetailOut(BaseModel):
    customer: CustomerOut
    orders_completed: int
    total_spent: Decimal
    recent_orders: list[CustomerOrderOut]
