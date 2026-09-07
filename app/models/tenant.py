import uuid
from datetime import datetime
from typing import TYPE_CHECKING

from sqlalchemy import CHAR, Boolean, DateTime, Integer, String, text
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.branch import Branch
    from app.models.order_status import OrderStatus
    from app.models.role import Role
    from app.models.tax_rate import TaxRate
    from app.models.user import User


class Tenant(Base):
    __tablename__ = "tenants"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    name: Mapped[str] = mapped_column(String(150), nullable=False)
    business_type: Mapped[str] = mapped_column(String(50), nullable=False, server_default="fast_food")
    currency: Mapped[str] = mapped_column(CHAR(3), nullable=False, server_default="COP")
    settings_version: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    settings: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=text("now()"))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=text("now()"))

    branches: Mapped[list["Branch"]] = relationship(back_populates="tenant")
    roles: Mapped[list["Role"]] = relationship(back_populates="tenant")
    users: Mapped[list["User"]] = relationship(back_populates="tenant")
    tax_rates: Mapped[list["TaxRate"]] = relationship(back_populates="tenant")
    order_statuses: Mapped[list["OrderStatus"]] = relationship(back_populates="tenant")
