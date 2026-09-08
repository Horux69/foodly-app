import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session, joinedload, selectinload

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


def get(db: Session, tenant_id: uuid.UUID, user_id: uuid.UUID) -> User | None:
    stmt = (
        select(User)
        .options(joinedload(User.role))
        .where(User.tenant_id == tenant_id, User.id == user_id)
    )
    return db.scalars(stmt).first()


def get_by_email_in_tenant(db: Session, tenant_id: uuid.UUID, email: str) -> User | None:
    stmt = select(User).where(User.tenant_id == tenant_id, User.email == email)
    return db.scalars(stmt).first()


def list_for_tenant(db: Session, tenant_id: uuid.UUID) -> list[User]:
    stmt = (
        select(User)
        .options(joinedload(User.role))
        .where(User.tenant_id == tenant_id)
        .order_by(User.name)
    )
    return list(db.scalars(stmt))


def create(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    role_id: uuid.UUID,
    branch_id: uuid.UUID | None,
    name: str,
    email: str,
    password_hash: str,
) -> User:
    user = User(
        tenant_id=tenant_id,
        role_id=role_id,
        branch_id=branch_id,
        name=name,
        email=email,
        password_hash=password_hash,
    )
    db.add(user)
    db.flush()
    return user
