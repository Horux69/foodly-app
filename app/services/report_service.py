import uuid
from datetime import date, timedelta

from sqlalchemy.orm import Session

from app.repositories import branch_repository, report_repository

DEFAULT_RANGE_DAYS = 30


class ReportError(Exception):
    pass


def resolve_range(from_date: date | None, to_date: date | None) -> tuple[date, date]:
    end = to_date or date.today()
    start = from_date or (end - timedelta(days=DEFAULT_RANGE_DAYS))
    if start > end:
        raise ReportError("La fecha inicial no puede ser posterior a la final")
    return start, end


def _check_branch(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID | None) -> None:
    if branch_id is None:
        return
    if branch_repository.get(db, tenant_id, branch_id) is None:
        raise ReportError("La sucursal no existe para este tenant")


def sales(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID | None,
    from_date: date | None,
    to_date: date | None,
) -> dict:
    start, end = resolve_range(from_date, to_date)
    _check_branch(db, tenant_id, branch_id)
    args = (tenant_id, branch_id, start, end)
    return {
        "from_date": start,
        "to_date": end,
        "totals": report_repository.sales_totals(db, *args),
        "by_day": report_repository.sales_by_day(db, *args),
        "by_channel": report_repository.sales_by_channel(db, *args),
        "by_branch": report_repository.sales_by_branch(db, *args),
    }


def top_products(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID | None,
    from_date: date | None,
    to_date: date | None,
    limit: int,
) -> list[dict]:
    start, end = resolve_range(from_date, to_date)
    _check_branch(db, tenant_id, branch_id)
    return report_repository.top_products(db, tenant_id, branch_id, start, end, limit)


def prep_times(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID | None,
    from_date: date | None,
    to_date: date | None,
) -> dict:
    start, end = resolve_range(from_date, to_date)
    _check_branch(db, tenant_id, branch_id)
    return report_repository.prep_times(db, tenant_id, branch_id, start, end)


def peak_hours(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID | None,
    from_date: date | None,
    to_date: date | None,
) -> list[dict]:
    start, end = resolve_range(from_date, to_date)
    _check_branch(db, tenant_id, branch_id)
    return report_repository.peak_hours(db, tenant_id, branch_id, start, end)
