import uuid
from typing import TYPE_CHECKING

from sqlalchemy import Boolean, Column, ForeignKey, String, Table, UniqueConstraint, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.permission import Permission
    from app.models.tenant import Tenant
    from app.models.user import User

role_permissions = Table(
    "role_permissions",
    Base.metadata,
    Column("role_id", UUID(as_uuid=True), ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True),
    Column(
        "permission_id", UUID(as_uuid=True), ForeignKey("permissions.id", ondelete="CASCADE"), primary_key=True
    ),
)


class Role(Base):
    """Los roles son configurables por tenant; el catalogo de permisos que agrupan no."""

    __tablename__ = "roles"
    __table_args__ = (UniqueConstraint("tenant_id", "code"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    tenant_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=False
    )
    code: Mapped[str] = mapped_column(String(40), nullable=False)
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    is_system: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))

    tenant: Mapped["Tenant"] = relationship(back_populates="roles")
    permissions: Mapped[list["Permission"]] = relationship(secondary=role_permissions, back_populates="roles")
    users: Mapped[list["User"]] = relationship(back_populates="role")
