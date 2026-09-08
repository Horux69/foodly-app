"""Toma de pedidos: valida disponibilidad y modificadores, congela precios
y calcula totales. Un solo lugar orquesta todo esto; el resto de la app
(incluido el futuro agente de WhatsApp) debe pasar por aqui, nunca repetir
esta logica.
"""

import uuid
from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal
from zoneinfo import ZoneInfo

from sqlalchemy.orm import Session

from app.domain.branch_schedule import ScheduleWindow, is_branch_open
from app.domain.menu_pricing import resolve_effective_menu_item
from app.domain.modifier_validation import ModifierGroupConstraint, ModifierValidationError, validate_selection
from app.domain.order_totals import LineInput, compute_totals
from app.domain.tenant_settings import parse as parse_settings
from app.models.order import Order
from app.repositories import (
    branch_repository,
    customer_repository,
    menu_repository,
    order_repository,
    order_status_repository,
    table_repository,
    tenant_repository,
)


class OrderError(Exception):
    pass


@dataclass(frozen=True)
class OrderLineInput:
    menu_item_id: uuid.UUID
    quantity: int
    modifier_ids: list[uuid.UUID]
    notes: str | None = None


def _check_branch_open(db: Session, branch, channel: str) -> None:
    schedules = branch_repository.get_schedules(db, branch.id)
    if not schedules:
        return  # sin horarios configurados: no restringe (evita bloquear tenants aun sin configurar)

    now_local = datetime.now(ZoneInfo(branch.timezone))
    # Convencion: weekday() de Python (Monday=0..Sunday=6), igual que se
    # asume al sembrar branch_schedules. Si branch_schedules llega a poblarse
    # desde otro origen con otra convencion, hay que normalizar aqui.
    windows = [
        ScheduleWindow(
            weekday=s.weekday, opens_at=s.opens_at, closes_at=s.closes_at, channel=s.channel, is_active=s.is_active
        )
        for s in schedules
    ]
    if not is_branch_open(weekday=now_local.weekday(), at=now_local.time(), channel=channel, schedules=windows):
        raise OrderError("La sucursal esta cerrada para este canal en este momento")


def create_order(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    branch_id: uuid.UUID,
    created_by: uuid.UUID | None,
    channel: str,
    items: list[OrderLineInput],
    customer_phone: str | None = None,
    customer_name: str | None = None,
    table_code: str | None = None,
    notes: str | None = None,
    idempotency_key: str | None = None,
    delivery_fee: Decimal = Decimal("0"),
    discount: Decimal = Decimal("0"),
    tip: Decimal = Decimal("0"),
) -> Order:
    if idempotency_key:
        existing = order_repository.get_by_idempotency_key(db, tenant_id, idempotency_key)
        if existing is not None:
            return existing

    if not items:
        raise OrderError("El pedido necesita al menos un producto")

    tenant = tenant_repository.get(db, tenant_id)
    if tenant is None:
        raise OrderError("El tenant no existe")

    # Modulo 9: lo que el restaurante puede vender y como sale de su
    # configuracion, no de condicionales por tenant en el codigo.
    settings = parse_settings(tenant.settings, business_type=tenant.business_type)
    if not settings.allows_channel(channel):
        raise OrderError(f"El canal '{channel}' no esta habilitado. Activos: {list(settings.channels)}")
    if table_code and not settings.uses_tables:
        raise OrderError("Este restaurante no maneja mesas")
    if tip and not settings.asks_tip:
        raise OrderError("Este restaurante no recibe propina")

    branch = branch_repository.get(db, tenant_id, branch_id)
    if branch is None:
        raise OrderError("La sucursal no existe para este tenant")

    _check_branch_open(db, branch, channel)

    initial_status = order_status_repository.get_initial(db, tenant_id)
    if initial_status is None:
        raise OrderError("El tenant no tiene un estado inicial de pedido configurado")

    customer_id = None
    if customer_phone:
        customer = customer_repository.get_or_create_by_phone(
            db, tenant_id=tenant_id, phone=customer_phone, name=customer_name
        )
        customer_id = customer.id

    table_id = None
    if table_code:
        table = table_repository.get_by_code(db, branch_id=branch_id, code=table_code)
        if table is None:
            raise OrderError(f"La mesa '{table_code}' no existe en esta sucursal")
        table_id = table.id

    resolved_lines = []  # (menu_item, OrderLineInput, [resolved modifiers], LineInput)
    for line in items:
        item = menu_repository.get_item_with_modifiers(db, tenant_id, line.menu_item_id)
        if item is None or item.is_archived:
            raise OrderError(f"El producto {line.menu_item_id} no existe")

        override = menu_repository.get_branch_override(db, branch_id, item.id)
        effective = resolve_effective_menu_item(
            base_price=item.base_price,
            base_is_available=item.is_available,
            override_price=override.price if override else None,
            override_is_available=override.is_available if override else None,
        )
        if not effective.is_available:
            raise OrderError(f"'{item.name}' no esta disponible en esta sucursal")

        modifiers_by_id = {m.id: m for group in item.modifier_groups for m in group.modifiers}
        selected_ids = set(line.modifier_ids)

        unknown = selected_ids - modifiers_by_id.keys()
        if unknown:
            raise OrderError(f"'{item.name}' no tiene los modificadores {unknown}")

        for group in item.modifier_groups:
            group_modifier_ids = {m.id for m in group.modifiers}
            selected_in_group = selected_ids & group_modifier_ids
            constraint = ModifierGroupConstraint(
                group_id=str(group.id),
                name=group.name,
                min_select=group.min_select,
                max_select=group.max_select,
                is_required=group.is_required,
            )
            try:
                validate_selection(constraint, len(selected_in_group))
            except ModifierValidationError as exc:
                raise OrderError(f"'{item.name}': {exc}") from exc

        resolved_modifiers = []
        for modifier_id in selected_ids:
            modifier = modifiers_by_id[modifier_id]
            if not modifier.is_available:
                raise OrderError(f"El modificador '{modifier.name}' no esta disponible")
            resolved_modifiers.append(modifier)

        tax_rate = item.tax_rate.rate if item.tax_rate else Decimal("0")
        tax_included = item.tax_rate.included_in_price if item.tax_rate else True

        line_input = LineInput(
            quantity=line.quantity,
            unit_price=effective.price,
            tax_rate=tax_rate,
            tax_included_in_price=tax_included,
            modifier_deltas=[m.price_delta for m in resolved_modifiers],
        )
        resolved_lines.append((item, line, resolved_modifiers, line_input))

    totals = compute_totals(
        [line_input for _, _, _, line_input in resolved_lines],
        delivery_fee=delivery_fee,
        discount=discount,
        tip=tip,
    )

    order_number = order_repository.next_order_number(db, branch_id)
    order = order_repository.create(
        db,
        tenant_id=tenant_id,
        branch_id=branch_id,
        status_id=initial_status.id,
        order_number=order_number,
        channel=channel,
        customer_id=customer_id,
        table_id=table_id,
        created_by=created_by,
        idempotency_key=idempotency_key,
        notes=notes,
        subtotal=totals.subtotal,
        tax_total=totals.tax_total,
        delivery_fee=totals.delivery_fee,
        discount=totals.discount,
        tip=totals.tip,
        total=totals.total,
    )

    for (item, line, resolved_modifiers, line_input), line_result in zip(resolved_lines, totals.lines, strict=True):
        order_item = order_repository.add_item(
            db,
            order_id=order.id,
            menu_item_id=item.id,
            name_snapshot=item.name,
            quantity=line.quantity,
            unit_price=line_input.unit_price,
            tax_rate=line_input.tax_rate,
            tax_amount=line_result.tax_amount,
            line_total=line_result.line_total,
            notes=line.notes,
        )
        for modifier in resolved_modifiers:
            order_repository.add_item_modifier(
                db,
                order_item_id=order_item.id,
                modifier_id=modifier.id,
                name_snapshot=modifier.name,
                price_delta=modifier.price_delta,
            )

    order_repository.add_status_history(
        db, order_id=order.id, status_id=initial_status.id, changed_by=created_by, note="Pedido creado"
    )

    db.commit()
    db.refresh(order)
    return order


def get_order(db: Session, *, tenant_id: uuid.UUID, order_id: uuid.UUID) -> Order | None:
    return order_repository.get_by_id(db, tenant_id, order_id)


def list_orders(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> list[Order]:
    return order_repository.list_for_branch(db, tenant_id, branch_id)
