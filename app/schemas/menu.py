import uuid
from decimal import Decimal

from pydantic import BaseModel, Field


class MenuCategoryCreate(BaseModel):
    name: str = Field(min_length=1, max_length=100)
    sort_order: int = 0


class MenuCategoryOut(BaseModel):
    id: uuid.UUID
    name: str
    sort_order: int

    model_config = {"from_attributes": True}


class MenuItemCreate(BaseModel):
    category_id: uuid.UUID
    name: str = Field(min_length=1, max_length=150)
    base_price: Decimal = Field(ge=0)
    description: str | None = None
    prep_minutes: int | None = None


class MenuItemOut(BaseModel):
    id: uuid.UUID
    name: str
    description: str | None
    price: Decimal
    is_available: bool


class MenuCategoryWithItemsOut(BaseModel):
    id: uuid.UUID
    name: str
    items: list[MenuItemOut]


class MenuItemAvailabilityUpdate(BaseModel):
    is_available: bool


class BranchMenuOverrideIn(BaseModel):
    branch_id: uuid.UUID
    price: Decimal | None = Field(default=None, ge=0)
    is_available: bool | None = None


class BranchMenuOverrideOut(BaseModel):
    branch_id: uuid.UUID
    menu_item_id: uuid.UUID
    price: Decimal | None
    is_available: bool | None

    model_config = {"from_attributes": True}
