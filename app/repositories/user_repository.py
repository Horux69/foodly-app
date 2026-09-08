import uuid

from sqlalchemy import select, text
from sqlalchemy.orm import Session, joinedload, selectinload

from app.models.role import Role
from app.models.user import User


def find_tenant_for_login(db: Session, email: str) -> uuid.UUID | None:
    """Resuelve a que empresa pertenece un email, antes de tener tenant.

    Es la unica consulta del sistema que mira a traves de las empresas, y por
    eso vive en una funcion SECURITY DEFINER de la base (ver la migracion 002)
    que solo devuelve el tenant_id. Con ese dato la aplicacion fija el
    contexto y todo lo demas vuelve a pasar por RLS.
    """
    return db.scalar(text("SELECT auth_tenant_for_email(:email)"), {"email": email})


def get_by_email(db: Session, email: str) -> User | None:
    """Busca un usuario por email dentro del tenant ya fijado en la sesion."""
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
