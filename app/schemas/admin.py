import uuid
from decimal import Decimal

from pydantic import BaseModel, Field


class TenantSettingsOut(BaseModel):
    tenant_id: uuid.UUID
    name: str
    business_type: str
    currency: str
    channels: list[str]
    uses_tables: bool
    asks_tip: bool


class TenantSettingsUpdate(BaseModel):
    """Parche parcial: lo que no venga conserva su valor actual."""

    channels: list[str] | None = None
    uses_tables: bool | None = None
    asks_tip: bool | None = None


class BranchCreate(BaseModel):
    name: str = Field(min_length=1, max_length=150)
    code: str = Field(min_length=1, max_length=10)
    timezone: str = "America/Bogota"
    address: str | None = None
    phone: str | None = None


class BranchOut(BaseModel):
    id: uuid.UUID
    name: str
    code: str
    timezone: str
    address: str | None
    phone: str | None
    is_active: bool

    model_config = {"from_attributes": True}


class ActiveUpdate(BaseModel):
    is_active: bool


class TaxRateCreate(BaseModel):
    name: str = Field(min_length=1, max_length=80)
    # Fraccion, no porcentaje: 8% se escribe 0.08. El tope evita el error de
    # digitacion mas comun, que es escribir 8 y cobrar 800%.
    rate: Decimal = Field(ge=0, lt=1, description="Fraccion decimal: 0.08 es 8%")
    included_in_price: bool = True
    is_default: bool = False


class TaxRateOut(BaseModel):
    id: uuid.UUID
    name: str
    rate: Decimal
    included_in_price: bool
    is_default: bool
    is_active: bool

    model_config = {"from_attributes": True}


class TableCreate(BaseModel):
    code: str = Field(min_length=1, max_length=20)
    capacity: int = Field(default=4, gt=0)


class TableOut(BaseModel):
    id: uuid.UUID
    code: str
    capacity: int
    is_active: bool

    model_config = {"from_attributes": True}


class PermissionOut(BaseModel):
    code: str
    description: str | None

    model_config = {"from_attributes": True}


class RoleCreate(BaseModel):
    code: str = Field(min_length=1, max_length=40)
    name: str = Field(min_length=1, max_length=100)
    permissions: list[str] = Field(default_factory=list)


class RolePermissionsUpdate(BaseModel):
    permissions: list[str]


class RoleOut(BaseModel):
    id: uuid.UUID
    code: str
    name: str
    is_system: bool
    permissions: list[str]


class UserCreate(BaseModel):
    name: str = Field(min_length=1, max_length=150)
    email: str = Field(min_length=3, max_length=150)
    password: str = Field(min_length=8, max_length=72)
    role_id: uuid.UUID
    branch_id: uuid.UUID | None = None


class UserOut(BaseModel):
    id: uuid.UUID
    name: str
    email: str
    role_id: uuid.UUID
    role_code: str
    branch_id: uuid.UUID | None
    is_active: bool
