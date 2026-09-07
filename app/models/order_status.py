import uuid
from typing import TYPE_CHECKING

from sqlalchemy import Boolean, ForeignKey, Integer, String, UniqueConstraint, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.tenant import Tenant


class OrderStatus(Base):
    """Estado de pedido configurable por tenant.

    La logica de negocio nunca compara por `code`: usa `category`, que es
    la unica parte normalizada entre restaurantes (ver app/domain/status_machine.py).
    """

    __tablename__ = "order_statuses"
    __table_args__ = (UniqueConstraint("tenant_id", "code"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    tenant_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=False
    )
    code: Mapped[str] = mapped_column(String(40), nullable=False)
    name: Mapped[str] = mapped_column(String(80), nullable=False)
    category: Mapped[str] = mapped_column(String(20), nullable=False)
    color: Mapped[str | None] = mapped_column(String(9))
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    is_initial: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))
    is_final: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))

    tenant: Mapped["Tenant"] = relationship(back_populates="order_statuses")


class OrderStatusTransition(Base):
    __tablename__ = "order_status_transitions"
    __table_args__ = (UniqueConstraint("from_status_id", "to_status_id"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    from_status_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("order_statuses.id", ondelete="CASCADE"), nullable=False
    )
    to_status_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("order_statuses.id", ondelete="CASCADE"), nullable=False
    )
    required_permission: Mapped[str | None] = mapped_column(String(60))
