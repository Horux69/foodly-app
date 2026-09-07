import uuid
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.orm import Session, joinedload

from app.models.menu import BranchMenuOverride, MenuCategory, MenuItem
from app.models.modifier import Modifier, ModifierGroup


def list_categories_with_items(db: Session, tenant_id: uuid.UUID) -> list[MenuCategory]:
    stmt = (
        select(MenuCategory)
        .options(joinedload(MenuCategory.items))
        .where(MenuCategory.tenant_id == tenant_id, MenuCategory.is_active.is_(True))
        .order_by(MenuCategory.sort_order)
    )
    return list(db.scalars(stmt).unique())


def get_overrides_for_branch(db: Session, branch_id: uuid.UUID) -> dict[uuid.UUID, BranchMenuOverride]:
    stmt = select(BranchMenuOverride).where(BranchMenuOverride.branch_id == branch_id)
    return {o.menu_item_id: o for o in db.scalars(stmt)}


def get_category(db: Session, tenant_id: uuid.UUID, category_id: uuid.UUID) -> MenuCategory | None:
    stmt = select(MenuCategory).where(MenuCategory.tenant_id == tenant_id, MenuCategory.id == category_id)
    return db.scalars(stmt).first()


def create_category(db: Session, *, tenant_id: uuid.UUID, name: str, sort_order: int = 0) -> MenuCategory:
    category = MenuCategory(tenant_id=tenant_id, name=name, sort_order=sort_order)
    db.add(category)
    db.flush()
    return category


def get_item(db: Session, tenant_id: uuid.UUID, item_id: uuid.UUID) -> MenuItem | None:
    stmt = (
        select(MenuItem)
        .join(MenuCategory, MenuItem.category_id == MenuCategory.id)
        .where(MenuCategory.tenant_id == tenant_id, MenuItem.id == item_id)
    )
    return db.scalars(stmt).first()


def get_item_with_modifiers(db: Session, tenant_id: uuid.UUID, item_id: uuid.UUID) -> MenuItem | None:
    stmt = (
        select(MenuItem)
        .join(MenuCategory, MenuItem.category_id == MenuCategory.id)
        .options(
            joinedload(MenuItem.tax_rate),
            joinedload(MenuItem.modifier_groups).joinedload(ModifierGroup.modifiers),
        )
        .where(MenuCategory.tenant_id == tenant_id, MenuItem.id == item_id)
    )
    return db.scalars(stmt).unique().first()


def get_modifier(db: Session, modifier_id: uuid.UUID) -> Modifier | None:
    return db.get(Modifier, modifier_id)


def create_item(
    db: Session,
    *,
    category_id: uuid.UUID,
    name: str,
    base_price: Decimal,
    tax_rate_id: uuid.UUID | None = None,
    description: str | None = None,
    prep_minutes: int | None = None,
    sort_order: int = 0,
) -> MenuItem:
    item = MenuItem(
        category_id=category_id,
        name=name,
        base_price=base_price,
        tax_rate_id=tax_rate_id,
        description=description,
        prep_minutes=prep_minutes,
        sort_order=sort_order,
    )
    db.add(item)
    db.flush()
    return item


def get_branch_override(db: Session, branch_id: uuid.UUID, menu_item_id: uuid.UUID) -> BranchMenuOverride | None:
    stmt = select(BranchMenuOverride).where(
        BranchMenuOverride.branch_id == branch_id, BranchMenuOverride.menu_item_id == menu_item_id
    )
    return db.scalars(stmt).first()
