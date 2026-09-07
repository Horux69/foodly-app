import uuid
from decimal import Decimal
from typing import TYPE_CHECKING

from sqlalchemy import Boolean, ForeignKey, Integer, Numeric, String, Text, UniqueConstraint, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.branch import Branch
    from app.models.modifier import ModifierGroup
    from app.models.tax_rate import TaxRate
    from app.models.tenant import Tenant


class MenuCategory(Base):
    __tablename__ = "menu_categories"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    tenant_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))

    tenant: Mapped["Tenant"] = relationship()
    items: Mapped[list["MenuItem"]] = relationship(back_populates="category")


class MenuItem(Base):
    __tablename__ = "menu_items"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    category_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("menu_categories.id", ondelete="CASCADE"), nullable=False
    )
    tax_rate_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("tax_rates.id"))
    name: Mapped[str] = mapped_column(String(150), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    base_price: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False)
    image_url: Mapped[str | None] = mapped_column(String(255))
    prep_minutes: Mapped[int | None] = mapped_column(Integer)
    is_available: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))
    is_archived: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")

    category: Mapped["MenuCategory"] = relationship(back_populates="items")
    tax_rate: Mapped["TaxRate | None"] = relationship()
    branch_overrides: Mapped[list["BranchMenuOverride"]] = relationship(back_populates="menu_item")
    modifier_groups: Mapped[list["ModifierGroup"]] = relationship(
        secondary="item_modifier_groups", back_populates="items"
    )


class BranchMenuOverride(Base):
    """Precio/disponibilidad diferenciados por sucursal.

    Resolver el precio efectivo SIEMPRE via app.domain.menu_pricing;
    nunca comparar `price is None` sueltos en otras capas.
    """

    __tablename__ = "branch_menu_overrides"
    __table_args__ = (UniqueConstraint("branch_id", "menu_item_id"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    branch_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("branches.id", ondelete="CASCADE"), nullable=False
    )
    menu_item_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("menu_items.id", ondelete="CASCADE"), nullable=False
    )
    price: Mapped[Decimal | None] = mapped_column(Numeric(10, 2))
    is_available: Mapped[bool | None] = mapped_column(Boolean)

    branch: Mapped["Branch"] = relationship()
    menu_item: Mapped["MenuItem"] = relationship(back_populates="branch_overrides")
