import uuid
from decimal import Decimal

from sqlalchemy import select, text
from sqlalchemy.orm import Session, joinedload

from app.models.order import Order, OrderItem, OrderItemModifier, OrderStatusHistory
from app.models.order_status import OrderStatus


def next_order_number(db: Session, branch_id: uuid.UUID) -> str:
    """Numeracion segura ante concurrencia: delega en la funcion de Postgres
    `next_order_number`, que incrementa `branches.order_seq` atomicamente."""
    return db.scalar(text("SELECT next_order_number(:branch_id)"), {"branch_id": branch_id})


def get_by_idempotency_key(db: Session, tenant_id: uuid.UUID, key: str) -> Order | None:
    stmt = select(Order).where(Order.tenant_id == tenant_id, Order.idempotency_key == key)
    return db.scalars(stmt).first()


def get_by_id(db: Session, tenant_id: uuid.UUID, order_id: uuid.UUID) -> Order | None:
    stmt = (
        select(Order)
        .options(joinedload(Order.items).joinedload(OrderItem.modifiers))
        .where(Order.tenant_id == tenant_id, Order.id == order_id)
    )
    return db.scalars(stmt).unique().first()


def list_for_branch(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID, limit: int = 50) -> list[Order]:
    stmt = (
        select(Order)
        .where(Order.tenant_id == tenant_id, Order.branch_id == branch_id)
        .order_by(Order.created_at.desc())
        .limit(limit)
    )
    return list(db.scalars(stmt))


def list_by_status_categories(
    db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID, categories: list[str]
) -> list[Order]:
    """Filtra por `order_statuses.category`, nunca por `code`: cada restaurante
    nombra sus estados distinto pero la categoria es la parte normalizada."""
    stmt = (
        select(Order)
        .join(OrderStatus, Order.status_id == OrderStatus.id)
        .options(
            joinedload(Order.status),
            joinedload(Order.table),
            joinedload(Order.items).joinedload(OrderItem.modifiers),
        )
        .where(Order.tenant_id == tenant_id, Order.branch_id == branch_id, OrderStatus.category.in_(categories))
        .order_by(Order.created_at)
    )
    return list(db.scalars(stmt).unique())


def create(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID,
    status_id: uuid.UUID,
    order_number: str,
    channel: str,
    customer_id: uuid.UUID | None,
    table_id: uuid.UUID | None,
    created_by: uuid.UUID | None,
    idempotency_key: str | None,
    notes: str | None,
    subtotal: Decimal,
    tax_total: Decimal,
    delivery_fee: Decimal,
    discount: Decimal,
    tip: Decimal,
    total: Decimal,
) -> Order:
    order = Order(
        tenant_id=tenant_id,
        branch_id=branch_id,
        status_id=status_id,
        order_number=order_number,
        channel=channel,
        customer_id=customer_id,
        table_id=table_id,
        created_by=created_by,
        idempotency_key=idempotency_key,
        notes=notes,
        subtotal=subtotal,
        tax_total=tax_total,
        delivery_fee=delivery_fee,
        discount=discount,
        tip=tip,
        total=total,
    )
    db.add(order)
    db.flush()
    return order


def add_item(
    db: Session,
    *,
    order_id: uuid.UUID,
    menu_item_id: uuid.UUID,
    name_snapshot: str,
    quantity: int,
    unit_price: Decimal,
    tax_rate: Decimal,
    tax_amount: Decimal,
    line_total: Decimal,
    notes: str | None,
) -> OrderItem:
    item = OrderItem(
        order_id=order_id,
        menu_item_id=menu_item_id,
        name_snapshot=name_snapshot,
        quantity=quantity,
        unit_price=unit_price,
        tax_rate=tax_rate,
        tax_amount=tax_amount,
        line_total=line_total,
        notes=notes,
    )
    db.add(item)
    db.flush()
    return item


def add_item_modifier(
    db: Session, *, order_item_id: uuid.UUID, modifier_id: uuid.UUID, name_snapshot: str, price_delta: Decimal
) -> OrderItemModifier:
    modifier = OrderItemModifier(
        order_item_id=order_item_id,
        modifier_id=modifier_id,
        name_snapshot=name_snapshot,
        price_delta=price_delta,
    )
    db.add(modifier)
    return modifier


def add_status_history(
    db: Session, *, order_id: uuid.UUID, status_id: uuid.UUID, changed_by: uuid.UUID | None, note: str | None
) -> OrderStatusHistory:
    entry = OrderStatusHistory(order_id=order_id, status_id=status_id, changed_by=changed_by, note=note)
    db.add(entry)
    return entry
