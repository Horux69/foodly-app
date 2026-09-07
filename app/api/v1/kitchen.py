import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.schemas.kitchen import KitchenItemOut, KitchenOrderOut
from app.schemas.order import OrderStatusOut
from app.services.kitchen_service import get_board

router = APIRouter()


@router.get("/orders", response_model=list[KitchenOrderOut])
def kitchen_board_endpoint(
    ctx: Annotated[RequestContext, Depends(require("orders.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[KitchenOrderOut]:
    if ctx.branch_id is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "El usuario no tiene una sucursal asignada")

    board = get_board(db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=uuid.UUID(ctx.branch_id))
    return [
        KitchenOrderOut(
            id=entry.order.id,
            order_number=entry.order.order_number,
            channel=entry.order.channel,
            table_code=entry.order.table.code if entry.order.table else None,
            created_at=entry.order.created_at,
            status=OrderStatusOut.model_validate(entry.order.status),
            items=[
                KitchenItemOut(
                    name_snapshot=item.name_snapshot,
                    quantity=item.quantity,
                    notes=item.notes,
                    modifiers=[m.name_snapshot for m in item.modifiers],
                )
                for item in entry.order.items
            ],
            next_statuses=[OrderStatusOut.model_validate(s) for s in entry.next_statuses],
        )
        for entry in board
    ]
