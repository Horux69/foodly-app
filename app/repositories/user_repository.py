from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.models.role import Role
from app.models.user import User


def get_by_email(db: Session, email: str) -> User | None:
    """Busca un usuario por email, sin filtrar por tenant.

    Unico punto de la aplicacion que consulta `users` sin tenant_id conocido:
    en login todavia no existe un token del que extraerlo. Ver app/services/auth.py.
    """
    stmt = (
        select(User)
        .options(selectinload(User.role).selectinload(Role.permissions))
        .where(User.email == email, User.is_active.is_(True))
    )
    return db.scalars(stmt).first()
