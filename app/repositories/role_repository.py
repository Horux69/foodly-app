import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.models.permission import Permission
from app.models.role import Role


def get(db: Session, tenant_id: uuid.UUID, role_id: uuid.UUID) -> Role | None:
    stmt = (
        select(Role)
        .options(selectinload(Role.permissions))
        .where(Role.tenant_id == tenant_id, Role.id == role_id)
    )
    return db.scalars(stmt).first()


def get_by_code(db: Session, tenant_id: uuid.UUID, code: str) -> Role | None:
    stmt = select(Role).where(Role.tenant_id == tenant_id, Role.code == code)
    return db.scalars(stmt).first()


def list_for_tenant(db: Session, tenant_id: uuid.UUID) -> list[Role]:
    stmt = (
        select(Role)
        .options(selectinload(Role.permissions))
        .where(Role.tenant_id == tenant_id)
        .order_by(Role.name)
    )
    return list(db.scalars(stmt))


def create(db: Session, *, tenant_id: uuid.UUID, code: str, name: str) -> Role:
    role = Role(tenant_id=tenant_id, code=code, name=name)
    db.add(role)
    db.flush()
    return role


def list_permissions(db: Session) -> list[Permission]:
    return list(db.scalars(select(Permission).order_by(Permission.code)))


def permissions_by_codes(db: Session, codes: list[str]) -> list[Permission]:
    return list(db.scalars(select(Permission).where(Permission.code.in_(codes))))
