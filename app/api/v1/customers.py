import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.schemas.customer import CustomerDetailOut, CustomerOrderOut, CustomerOut, CustomerUpdate
from app.services import customer_service
from app.services.customer_service import CustomerError

router = APIRouter()


@router.get("", response_model=list[CustomerOut])
def search_endpoint(
    ctx: Annotated[RequestContext, Depends(require("customers.view"))],
    db: Annotated[Session, Depends(get_db)],
    q: Annotated[str | None, Query(description="Busca por teléfono o nombre")] = None,
) -> list[CustomerOut]:
    clientes = customer_service.search(db, tenant_id=uuid.UUID(ctx.tenant_id), term=q)
    return [CustomerOut.model_validate(c) for c in clientes]


@router.get("/{customer_id}", response_model=CustomerDetailOut)
def detail_endpoint(
    customer_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("customers.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> CustomerDetailOut:
    try:
        cliente, pedidos, resumen = customer_service.detail(
            db, tenant_id=uuid.UUID(ctx.tenant_id), customer_id=customer_id
        )
    except CustomerError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc

    return CustomerDetailOut(
        customer=CustomerOut.model_validate(cliente),
        orders_completed=resumen["orders"],
        total_spent=resumen["spent"],
        recent_orders=[
            CustomerOrderOut(
                id=o.id,
                order_number=o.order_number,
                channel=o.channel,
                total=o.total,
                created_at=o.created_at,
            )
            for o in pedidos
        ],
    )


@router.patch("/{customer_id}", response_model=CustomerOut)
def update_endpoint(
    customer_id: uuid.UUID,
    payload: CustomerUpdate,
    ctx: Annotated[RequestContext, Depends(require("customers.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> CustomerOut:
    cambios = payload.model_dump(exclude_unset=True)
    if not cambios:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "No hay nada que actualizar")

    try:
        cliente = customer_service.update(
            db, tenant_id=uuid.UUID(ctx.tenant_id), customer_id=customer_id, **cambios
        )
    except CustomerError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return CustomerOut.model_validate(cliente)
