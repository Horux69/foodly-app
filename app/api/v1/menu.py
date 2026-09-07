import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.schemas.menu import (
    BranchMenuOverrideIn,
    BranchMenuOverrideOut,
    MenuCategoryCreate,
    MenuCategoryOut,
    MenuCategoryWithItemsOut,
    MenuItemAvailabilityUpdate,
    MenuItemCreate,
    MenuItemOut,
)
from app.services.menu_service import MenuError, create_category, create_item, get_menu, set_branch_override, set_item_availability

router = APIRouter()


@router.get("", response_model=list[MenuCategoryWithItemsOut])
def get_menu_endpoint(
    ctx: Annotated[RequestContext, Depends(require("menu.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[MenuCategoryWithItemsOut]:
    branch_id = uuid.UUID(ctx.branch_id) if ctx.branch_id else None
    categories = get_menu(db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id)
    return [
        MenuCategoryWithItemsOut(
            id=c.id,
            name=c.name,
            items=[MenuItemOut(id=i.id, name=i.name, description=i.description, price=i.price, is_available=i.is_available) for i in c.items],
        )
        for c in categories
    ]


@router.post("/categories", response_model=MenuCategoryOut, status_code=status.HTTP_201_CREATED)
def create_category_endpoint(
    payload: MenuCategoryCreate,
    ctx: Annotated[RequestContext, Depends(require("menu.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> MenuCategoryOut:
    category = create_category(db, tenant_id=uuid.UUID(ctx.tenant_id), name=payload.name, sort_order=payload.sort_order)
    return MenuCategoryOut.model_validate(category)


@router.post("/items", status_code=status.HTTP_201_CREATED)
def create_item_endpoint(
    payload: MenuItemCreate,
    ctx: Annotated[RequestContext, Depends(require("menu.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> dict:
    try:
        item = create_item(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            category_id=payload.category_id,
            name=payload.name,
            base_price=payload.base_price,
            description=payload.description,
            prep_minutes=payload.prep_minutes,
        )
    except MenuError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc))
    return {"id": item.id, "name": item.name, "base_price": item.base_price}


@router.patch("/items/{item_id}/availability")
def update_item_availability_endpoint(
    item_id: uuid.UUID,
    payload: MenuItemAvailabilityUpdate,
    ctx: Annotated[RequestContext, Depends(require("menu.availability"))],
    db: Annotated[Session, Depends(get_db)],
) -> dict:
    try:
        item = set_item_availability(
            db, tenant_id=uuid.UUID(ctx.tenant_id), item_id=item_id, is_available=payload.is_available
        )
    except MenuError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc))
    return {"id": item.id, "is_available": item.is_available}


@router.put("/items/{item_id}/branch-override", response_model=BranchMenuOverrideOut)
def set_branch_override_endpoint(
    item_id: uuid.UUID,
    payload: BranchMenuOverrideIn,
    ctx: Annotated[RequestContext, Depends(require("menu.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> BranchMenuOverrideOut:
    try:
        override = set_branch_override(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=payload.branch_id,
            item_id=item_id,
            price=payload.price,
            is_available=payload.is_available,
        )
    except MenuError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc))
    return BranchMenuOverrideOut.model_validate(override)
