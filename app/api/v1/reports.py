import uuid
from datetime import date
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.schemas.report import PeakHourOut, PrepTimesOut, SalesReportOut, TopProductOut
from app.services.report_service import ReportError, peak_hours, prep_times, sales, top_products

router = APIRouter()

# El branch_id es opcional y se valida contra el tenant del token: sin el,
# el reporte es de toda la empresa. El tenant_id nunca se acepta del cliente.
BranchFilter = Annotated[uuid.UUID | None, Query(description="Limita el reporte a una sucursal")]
FromDate = Annotated[date | None, Query(description="Inicio del periodo (por defecto, 30 dias atras)")]
ToDate = Annotated[date | None, Query(description="Fin del periodo (por defecto, hoy)")]


@router.get("/sales", response_model=SalesReportOut)
def sales_endpoint(
    ctx: Annotated[RequestContext, Depends(require("reports.view"))],
    db: Annotated[Session, Depends(get_db)],
    branch_id: BranchFilter = None,
    from_date: FromDate = None,
    to_date: ToDate = None,
) -> SalesReportOut:
    try:
        data = sales(
            db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id, from_date=from_date, to_date=to_date
        )
    except ReportError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return SalesReportOut.model_validate(data)


@router.get("/top-products", response_model=list[TopProductOut])
def top_products_endpoint(
    ctx: Annotated[RequestContext, Depends(require("reports.view"))],
    db: Annotated[Session, Depends(get_db)],
    branch_id: BranchFilter = None,
    from_date: FromDate = None,
    to_date: ToDate = None,
    limit: Annotated[int, Query(ge=1, le=100)] = 10,
) -> list[TopProductOut]:
    try:
        rows = top_products(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=branch_id,
            from_date=from_date,
            to_date=to_date,
            limit=limit,
        )
    except ReportError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return [TopProductOut.model_validate(r) for r in rows]


@router.get("/prep-times", response_model=PrepTimesOut)
def prep_times_endpoint(
    ctx: Annotated[RequestContext, Depends(require("reports.view"))],
    db: Annotated[Session, Depends(get_db)],
    branch_id: BranchFilter = None,
    from_date: FromDate = None,
    to_date: ToDate = None,
) -> PrepTimesOut:
    try:
        data = prep_times(
            db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id, from_date=from_date, to_date=to_date
        )
    except ReportError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return PrepTimesOut.model_validate(data)


@router.get("/peak-hours", response_model=list[PeakHourOut])
def peak_hours_endpoint(
    ctx: Annotated[RequestContext, Depends(require("reports.view"))],
    db: Annotated[Session, Depends(get_db)],
    branch_id: BranchFilter = None,
    from_date: FromDate = None,
    to_date: ToDate = None,
) -> list[PeakHourOut]:
    try:
        rows = peak_hours(
            db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id, from_date=from_date, to_date=to_date
        )
    except ReportError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return [PeakHourOut.model_validate(r) for r in rows]
