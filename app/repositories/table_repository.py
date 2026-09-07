import uuid

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.table import Table


def get_by_code(db: Session, *, branch_id: uuid.UUID, code: str) -> Table | None:
    stmt = select(Table).where(Table.branch_id == branch_id, Table.code == code, Table.is_active.is_(True))
    return db.scalars(stmt).first()
