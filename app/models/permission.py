import uuid
from typing import TYPE_CHECKING

from sqlalchemy import String, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base

if TYPE_CHECKING:
    from app.models.role import Role


class Permission(Base):
    """Catalogo global de permisos. Fijo: se siembra desde app/core/permissions.py.

    Las filas existen para que role_permissions tenga integridad referencial;
    el catalogo real de codigos vive en app/core/permissions.py.
    """

    __tablename__ = "permissions"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    code: Mapped[str] = mapped_column(String(60), nullable=False, unique=True)
    description: Mapped[str | None] = mapped_column(String(200))

    roles: Mapped[list["Role"]] = relationship(secondary="role_permissions", back_populates="permissions")
