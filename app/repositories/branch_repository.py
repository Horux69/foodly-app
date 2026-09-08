import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.branch import Branch, BranchSchedule


def get(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> Branch | None:
    stmt = select(Branch).where(Branch.tenant_id == tenant_id, Branch.id == branch_id)
    return db.scalars(stmt).first()


def list_for_tenant(db: Session, tenant_id: uuid.UUID) -> list[Branch]:
    stmt = select(Branch).where(Branch.tenant_id == tenant_id).order_by(Branch.name)
    return list(db.scalars(stmt))


def get_by_code(db: Session, tenant_id: uuid.UUID, code: str) -> Branch | None:
    stmt = select(Branch).where(Branch.tenant_id == tenant_id, Branch.code == code)
    return db.scalars(stmt).first()


def create(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    name: str,
    code: str,
    timezone: str,
    address: str | None,
    phone: str | None,
) -> Branch:
    branch = Branch(
        tenant_id=tenant_id, name=name, code=code, timezone=timezone, address=address, phone=phone
    )
    db.add(branch)
    db.flush()
    return branch


def get_schedules(db: Session, branch_id: uuid.UUID) -> list[BranchSchedule]:
    stmt = select(BranchSchedule).where(BranchSchedule.branch_id == branch_id, BranchSchedule.is_active.is_(True))
    return list(db.scalars(stmt))
