import uuid
from dataclasses import dataclass
from decimal import Decimal

from sqlalchemy.orm import Session

from app.domain.menu_pricing import resolve_effective_menu_item
from app.models.menu import BranchMenuOverride, MenuCategory, MenuItem
from app.repositories import branch_repository, menu_repository


class MenuError(Exception):
    pass


@dataclass(frozen=True)
class MenuItemView:
    id: uuid.UUID
    name: str
    description: str | None
    price: Decimal
    is_available: bool


@dataclass(frozen=True)
class MenuCategoryView:
    id: uuid.UUID
    name: str
    items: list[MenuItemView]


def get_menu(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID | None) -> list[MenuCategoryView]:
    """Menu con precio y disponibilidad efectivos para la sucursal dada.

    Si no hay sucursal (p.ej. un admin de tenant sin sucursal fija), se
    devuelven los valores base sin resolver overrides.
    """
    categories = menu_repository.list_categories_with_items(db, tenant_id)
    overrides = menu_repository.get_overrides_for_branch(db, branch_id) if branch_id else {}

    result: list[MenuCategoryView] = []
    for category in categories:
        items: list[MenuItemView] = []
        for item in sorted(category.items, key=lambda i: i.sort_order):
            if item.is_archived:
                continue
            override = overrides.get(item.id)
            effective = resolve_effective_menu_item(
                base_price=item.base_price,
                base_is_available=item.is_available,
                override_price=override.price if override else None,
                override_is_available=override.is_available if override else None,
            )
            items.append(
                MenuItemView(
                    id=item.id,
                    name=item.name,
                    description=item.description,
                    price=effective.price,
                    is_available=effective.is_available,
                )
            )
        result.append(MenuCategoryView(id=category.id, name=category.name, items=items))
    return result


def create_category(db: Session, *, tenant_id: uuid.UUID, name: str, sort_order: int = 0) -> MenuCategory:
    category = menu_repository.create_category(db, tenant_id=tenant_id, name=name, sort_order=sort_order)
    db.commit()
    db.refresh(category)
    return category


def create_item(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    category_id: uuid.UUID,
    name: str,
    base_price: Decimal,
    description: str | None = None,
    prep_minutes: int | None = None,
) -> MenuItem:
    category = menu_repository.get_category(db, tenant_id, category_id)
    if category is None:
        raise MenuError("La categoria no existe para este tenant")

    item = menu_repository.create_item(
        db,
        category_id=category.id,
        name=name,
        base_price=base_price,
        description=description,
        prep_minutes=prep_minutes,
    )
    db.commit()
    db.refresh(item)
    return item


def set_item_availability(db: Session, *, tenant_id: uuid.UUID, item_id: uuid.UUID, is_available: bool) -> MenuItem:
    item = menu_repository.get_item(db, tenant_id, item_id)
    if item is None:
        raise MenuError("El producto no existe para este tenant")

    item.is_available = is_available
    db.commit()
    db.refresh(item)
    return item


def set_branch_override(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID,
    item_id: uuid.UUID,
    price: Decimal | None,
    is_available: bool | None,
) -> BranchMenuOverride:
    item = menu_repository.get_item(db, tenant_id, item_id)
    if item is None:
        raise MenuError("El producto no existe para este tenant")

    branch = branch_repository.get(db, tenant_id, branch_id)
    if branch is None:
        raise MenuError("La sucursal no existe para este tenant")

    override = menu_repository.get_branch_override(db, branch_id, item_id)
    if override is None:
        override = BranchMenuOverride(branch_id=branch_id, menu_item_id=item_id)
        db.add(override)

    override.price = price
    override.is_available = is_available
    db.commit()
    db.refresh(override)
    return override
