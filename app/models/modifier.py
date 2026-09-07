import uuid
from decimal import Decimal
from typing import TYPE_CHECKING

from sqlalchemy import (
    Boolean,
    CheckConstraint,
    Column,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Table,
    text,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.menu import MenuItem
    from app.models.tenant import Tenant

item_modifier_groups = Table(
    "item_modifier_groups",
    Base.metadata,
    Column("item_id", UUID(as_uuid=True), ForeignKey("menu_items.id", ondelete="CASCADE"), primary_key=True),
    Column("group_id", UUID(as_uuid=True), ForeignKey("modifier_groups.id", ondelete="CASCADE"), primary_key=True),
    Column("sort_order", Integer, nullable=False, server_default="0"),
)


class ModifierGroup(Base):
    __tablename__ = "modifier_groups"
    __table_args__ = (CheckConstraint("max_select >= min_select"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    tenant_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    min_select: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    max_select: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    is_required: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))

    tenant: Mapped["Tenant"] = relationship()
    modifiers: Mapped[list["Modifier"]] = relationship(back_populates="group")
    items: Mapped[list["MenuItem"]] = relationship(secondary=item_modifier_groups, back_populates="modifier_groups")


class Modifier(Base):
    __tablename__ = "modifiers"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    group_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("modifier_groups.id", ondelete="CASCADE"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    price_delta: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    is_available: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))

    group: Mapped["ModifierGroup"] = relationship(back_populates="modifiers")
