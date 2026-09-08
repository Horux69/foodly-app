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
    # Sin impuesto explicito se hereda el default del tenant.
    tax_rate_id: uuid.UUID | None = None


class MenuItemUpdate(BaseModel):
    """Parche parcial: solo se toca lo que venga en el cuerpo.

    `tax_rate_id` en null es intencional y significa dejar el producto exento.
    """

    category_id: uuid.UUID | None = None
    name: str | None = Field(default=None, min_length=1, max_length=150)
    base_price: Decimal | None = Field(default=None, ge=0)
    description: str | None = None
    prep_minutes: int | None = None
    tax_rate_id: uuid.UUID | None = None
    sort_order: int | None = None
    is_archived: bool | None = None


class ModifierOut(BaseModel):
    id: uuid.UUID
    name: str
    price_delta: Decimal
    is_available: bool


class ModifierGroupOut(BaseModel):
    id: uuid.UUID
    name: str
    min_select: int
    max_select: int
    is_required: bool
    modifiers: list[ModifierOut]


class MenuItemOut(BaseModel):
    id: uuid.UUID
    name: str
    description: str | None
    price: Decimal
    is_available: bool
    modifier_groups: list[ModifierGroupOut] = []


class MenuCategoryWithItemsOut(BaseModel):
    id: uuid.UUID
    name: str
    items: list[MenuItemOut]


class CatalogCategoryOut(BaseModel):
    id: uuid.UUID
    name: str
    sort_order: int
    is_active: bool

    model_config = {"from_attributes": True}


class CatalogItemOut(BaseModel):
    id: uuid.UUID
    category_id: uuid.UUID
    name: str
    description: str | None
    base_price: Decimal
    tax_rate_id: uuid.UUID | None
    prep_minutes: int | None
    is_available: bool
    is_archived: bool
    sort_order: int

    model_config = {"from_attributes": True}


class CatalogOut(BaseModel):
    categories: list[CatalogCategoryOut]
    items: list[CatalogItemOut]


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
