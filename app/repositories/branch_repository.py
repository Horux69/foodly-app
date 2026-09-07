import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.branch import Branch, BranchSchedule


def get(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> Branch | None:
    stmt = select(Branch).where(Branch.tenant_id == tenant_id, Branch.id == branch_id)
    return db.scalars(stmt).first()


def get_schedules(db: Session, branch_id: uuid.UUID) -> list[BranchSchedule]:
    stmt = select(BranchSchedule).where(BranchSchedule.branch_id == branch_id, BranchSchedule.is_active.is_(True))
    return list(db.scalars(stmt))
