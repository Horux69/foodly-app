import uuid
from datetime import datetime
from decimal import Decimal

from pydantic import BaseModel, Field


class PaymentCreate(BaseModel):
    method: str
    amount: Decimal = Field(gt=0)
    external_reference: str | None = None
    idempotency_key: str | None = None


class PaymentOut(BaseModel):
    id: uuid.UUID
    method: str
    status: str
    amount: Decimal
    external_reference: str | None
    paid_at: datetime | None

    model_config = {"from_attributes": True}


class PaymentBalanceOut(BaseModel):
    total: Decimal
    paid: Decimal
    pending: Decimal
    is_settled: bool
