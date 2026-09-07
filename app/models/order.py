import uuid
from datetime import datetime
from decimal import Decimal
from typing import TYPE_CHECKING

from sqlalchemy import DateTime, ForeignKey, Integer, Numeric, String, Text, UniqueConstraint, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.branch import Branch
    from app.models.customer import Customer
    from app.models.menu import MenuItem
    from app.models.modifier import Modifier
    from app.models.order_status import OrderStatus
    from app.models.payment import Payment
    from app.models.table import Table
    from app.models.tenant import Tenant
    from app.models.user import User


class Order(Base):
    __tablename__ = "orders"
    __table_args__ = (
        UniqueConstraint("branch_id", "order_number"),
        UniqueConstraint("tenant_id", "idempotency_key"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    tenant_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=False
    )
    branch_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("branches.id", ondelete="CASCADE"), nullable=False
    )
    customer_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("customers.id", ondelete="SET NULL"))
    table_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("tables.id", ondelete="SET NULL"))
    status_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("order_statuses.id"), nullable=False)
    created_by: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"))
    order_number: Mapped[str] = mapped_column(String(30), nullable=False)
    channel: Mapped[str] = mapped_column(String(20), nullable=False)
    idempotency_key: Mapped[str | None] = mapped_column(String(80))
    subtotal: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    tax_total: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    delivery_fee: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    discount: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    tip: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    total: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    notes: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=text("now()"))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=text("now()"))

    tenant: Mapped["Tenant"] = relationship()
    branch: Mapped["Branch"] = relationship()
    customer: Mapped["Customer | None"] = relationship()
    table: Mapped["Table | None"] = relationship()
    status: Mapped["OrderStatus"] = relationship()
    created_by_user: Mapped["User | None"] = relationship()
    items: Mapped[list["OrderItem"]] = relationship(back_populates="order")
    status_history: Mapped[list["OrderStatusHistory"]] = relationship(back_populates="order")
    payments: Mapped[list["Payment"]] = relationship(back_populates="order")


class OrderItem(Base):
    __tablename__ = "order_items"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    order_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("orders.id", ondelete="CASCADE"), nullable=False
    )
    menu_item_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("menu_items.id"), nullable=False)
    name_snapshot: Mapped[str] = mapped_column(String(150), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False)
    unit_price: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False)
    tax_rate: Mapped[Decimal] = mapped_column(Numeric(5, 4), nullable=False, server_default="0")
    tax_amount: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    line_total: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    notes: Mapped[str | None] = mapped_column(String(255))

    order: Mapped["Order"] = relationship(back_populates="items")
    menu_item: Mapped["MenuItem"] = relationship()
    modifiers: Mapped[list["OrderItemModifier"]] = relationship(back_populates="order_item")


class OrderItemModifier(Base):
    __tablename__ = "order_item_modifiers"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    order_item_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("order_items.id", ondelete="CASCADE"), nullable=False
    )
    modifier_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("modifiers.id"), nullable=False)
    name_snapshot: Mapped[str] = mapped_column(String(100), nullable=False)
    price_delta: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")

    order_item: Mapped["OrderItem"] = relationship(back_populates="modifiers")
    modifier: Mapped["Modifier"] = relationship()


class OrderStatusHistory(Base):
    __tablename__ = "order_status_history"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    order_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("orders.id", ondelete="CASCADE"), nullable=False
    )
    status_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("order_statuses.id"), nullable=False)
    changed_by: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"))
    note: Mapped[str | None] = mapped_column(String(255))
    changed_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=text("now()"))

    order: Mapped["Order"] = relationship(back_populates="status_history")
