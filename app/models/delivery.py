import uuid
from datetime import datetime
from decimal import Decimal
from typing import TYPE_CHECKING

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, Numeric, String, text
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.branch import Branch
    from app.models.order import Order
    from app.models.user import User


class DeliveryZone(Base):
    """Zona de reparto de una sucursal: cuanto cobra llevar y cuanto hay que
    pedir como minimo."""

    __tablename__ = "delivery_zones"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    branch_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("branches.id", ondelete="CASCADE"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    fee: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    min_order: Mapped[Decimal] = mapped_column(Numeric(10, 2), nullable=False, server_default="0")
    est_minutes: Mapped[int | None] = mapped_column(Integer)
    # Reservado para dibujar la zona en un mapa; hoy nadie lo consulta.
    polygon: Mapped[dict | None] = mapped_column(JSONB)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))

    branch: Mapped["Branch"] = relationship()


class DeliveryInfo(Base):
    """Datos de entrega de un pedido. Uno por pedido como maximo."""

    __tablename__ = "delivery_info"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    order_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("orders.id", ondelete="CASCADE"), nullable=False, unique=True
    )
    zone_id: Mapped[uuid.UUID | None] = mapped_column(
        UUID(as_uuid=True), ForeignKey("delivery_zones.id")
    )
    courier_id: Mapped[uuid.UUID | None] = mapped_column(
        UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL")
    )
    address: Mapped[str] = mapped_column(String(255), nullable=False)
    lat: Mapped[Decimal | None] = mapped_column(Numeric(10, 7))
    lng: Mapped[Decimal | None] = mapped_column(Numeric(10, 7))
    estimated_time: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    dispatched_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    delivered_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    order: Mapped["Order"] = relationship(back_populates="delivery")
    zone: Mapped["DeliveryZone | None"] = relationship()
    courier: Mapped["User | None"] = relationship()
