import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.schemas.payment import PaymentBalanceOut, PaymentCreate, PaymentOut
from app.services.payment_service import PaymentError, get_balance, list_payments, register_payment

router = APIRouter()


@router.post("/{order_id}/payments", response_model=PaymentOut, status_code=status.HTTP_201_CREATED)
def register_payment_endpoint(
    order_id: uuid.UUID,
    payload: PaymentCreate,
    ctx: Annotated[RequestContext, Depends(require("payments.register"))],
    db: Annotated[Session, Depends(get_db)],
) -> PaymentOut:
    try:
        payment = register_payment(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            order_id=order_id,
            method=payload.method,
            amount=payload.amount,
            external_reference=payload.external_reference,
            idempotency_key=payload.idempotency_key,
        )
    except PaymentError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return PaymentOut.model_validate(payment)


@router.get("/{order_id}/payments", response_model=list[PaymentOut])
def list_payments_endpoint(
    order_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[PaymentOut]:
    try:
        payments = list_payments(db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id)
    except PaymentError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return [PaymentOut.model_validate(p) for p in payments]


@router.get("/{order_id}/balance", response_model=PaymentBalanceOut)
def get_balance_endpoint(
    order_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> PaymentBalanceOut:
    try:
        balance = get_balance(db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id)
    except PaymentError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return PaymentBalanceOut(
        total=balance.total, paid=balance.paid, pending=balance.pending, is_settled=balance.is_settled
    )
