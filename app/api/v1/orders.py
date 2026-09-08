import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, get_context, require
from app.core.database import get_db
from app.models.order import Order
from app.schemas.order import (
    OrderCreate,
    OrderItemModifierOut,
    OrderItemOut,
    OrderOut,
    OrderPreviewOut,
    OrderStatusChange,
    OrderStatusOut,
)
from app.services.order_service import OrderError, OrderLineInput
from app.services.order_service import create_order as create_order_use_case
from app.services.order_service import get_order as get_order_use_case
from app.services.order_service import list_orders as list_orders_use_case
from app.services.order_service import preview_totals as preview_totals_use_case
from app.services.order_status_service import OrderStatusError, advance_status, allowed_next_statuses

router = APIRouter()


def _to_order_out(order: Order) -> OrderOut:
    return OrderOut(
        id=order.id,
        order_number=order.order_number,
        channel=order.channel,
        subtotal=order.subtotal,
        tax_total=order.tax_total,
        delivery_fee=order.delivery_fee,
        discount=order.discount,
        tip=order.tip,
        total=order.total,
        notes=order.notes,
        items=[
            OrderItemOut(
                id=i.id,
                menu_item_id=i.menu_item_id,
                name_snapshot=i.name_snapshot,
                quantity=i.quantity,
                unit_price=i.unit_price,
                tax_amount=i.tax_amount,
                line_total=i.line_total,
                notes=i.notes,
                modifiers=[
                    OrderItemModifierOut(
                        modifier_id=m.modifier_id, name_snapshot=m.name_snapshot, price_delta=m.price_delta
                    )
                    for m in i.modifiers
                ],
            )
            for i in order.items
        ],
    )


@router.post("", response_model=OrderOut, status_code=status.HTTP_201_CREATED)
def create_order_endpoint(
    payload: OrderCreate,
    ctx: Annotated[RequestContext, Depends(require("orders.create"))],
    db: Annotated[Session, Depends(get_db)],
) -> OrderOut:
    if ctx.branch_id is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "El usuario no tiene una sucursal asignada")

    try:
        order = create_order_use_case(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=uuid.UUID(ctx.branch_id),
            created_by=uuid.UUID(ctx.user_id),
            channel=payload.channel,
            items=[
                OrderLineInput(
                    menu_item_id=i.menu_item_id, quantity=i.quantity, modifier_ids=i.modifier_ids, notes=i.notes
                )
                for i in payload.items
            ],
            customer_phone=payload.customer_phone,
            customer_name=payload.customer_name,
            table_code=payload.table_code,
            notes=payload.notes,
            idempotency_key=payload.idempotency_key,
            delivery_fee=payload.delivery_fee,
            discount=payload.discount,
            tip=payload.tip,
        )
    except OrderError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _to_order_out(order)


@router.post("/preview", response_model=OrderPreviewOut)
def preview_order_endpoint(
    payload: OrderCreate,
    ctx: Annotated[RequestContext, Depends(require("orders.create"))],
    db: Annotated[Session, Depends(get_db)],
) -> OrderPreviewOut:
    """Totales sin crear el pedido, para que la pantalla de venta los muestre
    sin repetir la aritmetica del dominio."""
    if ctx.branch_id is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "El usuario no tiene una sucursal asignada")

    try:
        totals = preview_totals_use_case(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=uuid.UUID(ctx.branch_id),
            items=[
                OrderLineInput(
                    menu_item_id=i.menu_item_id, quantity=i.quantity, modifier_ids=i.modifier_ids, notes=i.notes
                )
                for i in payload.items
            ],
            delivery_fee=payload.delivery_fee,
            discount=payload.discount,
            tip=payload.tip,
        )
    except OrderError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    return OrderPreviewOut(
        subtotal=totals.subtotal,
        tax_total=totals.tax_total,
        delivery_fee=totals.delivery_fee,
        discount=totals.discount,
        tip=totals.tip,
        total=totals.total,
    )


@router.get("/{order_id}", response_model=OrderOut)
def get_order_endpoint(
    order_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> OrderOut:
    order = get_order_use_case(db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id)
    if order is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Pedido no encontrado")
    return _to_order_out(order)


@router.get("", response_model=list[OrderOut])
def list_orders_endpoint(
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[OrderOut]:
    if ctx.branch_id is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "El usuario no tiene una sucursal asignada")
    orders = list_orders_use_case(db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=uuid.UUID(ctx.branch_id))
    return [_to_order_out(o) for o in orders]


@router.get("/{order_id}/next-statuses", response_model=list[OrderStatusOut])
def next_statuses_endpoint(
    order_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[OrderStatusOut]:
    order = get_order_use_case(db, tenant_id=uuid.UUID(ctx.tenant_id), order_id=order_id)
    if order is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Pedido no encontrado")
    statuses = allowed_next_statuses(db, tenant_id=uuid.UUID(ctx.tenant_id), order=order)
    return [OrderStatusOut.model_validate(s) for s in statuses]


# Sin require(...) a proposito: el permiso no lo fija el endpoint sino cada
# transicion configurada por el tenant (order_status_transitions.required_permission).
# La maquina de estados lo valida contra los permisos del token.
@router.post("/{order_id}/status", response_model=OrderOut)
def change_status_endpoint(
    order_id: uuid.UUID,
    payload: OrderStatusChange,
    ctx: Annotated[RequestContext, Depends(get_context)],
    db: Annotated[Session, Depends(get_db)],
) -> OrderOut:
    try:
        order = advance_status(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            order_id=order_id,
            to_status_id=payload.to_status_id,
            permissions=ctx.permissions,
            changed_by=uuid.UUID(ctx.user_id),
            note=payload.note,
        )
    except OrderStatusError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _to_order_out(order)
