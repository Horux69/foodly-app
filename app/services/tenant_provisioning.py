"""Aprovisionamiento de un tenant nuevo.

Al crear un tenant hay que sembrar su configuracion minima para operar:
estados de pedido, un rol admin con todos los permisos, y un impuesto por
defecto. El flujo de estados varia segun `business_type`; el rol y el
impuesto no. Si un modelo de negocio nuevo exige tocar codigo aqui en vez
de agregar un preset, el diseño esta fallando (ver docs/multi-tenancy.md).
"""

from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.domain.tenant_settings import defaults_for, validate
from app.models.order_status import OrderStatus, OrderStatusTransition
from app.models.permission import Permission
from app.models.role import Role
from app.models.tax_rate import TaxRate
from app.models.tenant import Tenant
from app.repositories import tenant_repository

# code, name, category, sort_order, is_initial, is_final
_StatusDef = tuple[str, str, str, int, bool, bool]
# from_code, to_code, required_permission
_TransitionDef = tuple[str, str, str | None]

STATUS_PRESETS: dict[str, dict[str, list]] = {
    "fast_food": {
        "statuses": [
            ("pending", "Pendiente", "new", 1, True, False),
            ("paid", "Pagado", "new", 2, False, False),
            ("preparing", "Preparacion", "kitchen", 3, False, False),
            ("ready", "Listo", "ready", 4, False, False),
            ("delivered", "Entregado", "completed", 5, False, True),
            ("cancelled", "Cancelado", "cancelled", 9, False, True),
        ],
        "transitions": [
            ("pending", "paid", "payments.register"),
            ("paid", "preparing", "orders.advance_kitchen"),
            ("preparing", "ready", "orders.advance_kitchen"),
            ("ready", "delivered", "orders.advance_kitchen"),
            ("pending", "cancelled", "orders.cancel"),
            ("paid", "cancelled", "orders.cancel"),
        ],
    },
    "table_service": {
        "statuses": [
            ("open", "Abierto", "new", 1, True, False),
            ("preparing", "Preparacion", "kitchen", 2, False, False),
            ("ready", "Listo", "ready", 3, False, False),
            ("served", "Servido", "completed", 4, False, True),
            ("cancelled", "Cancelado", "cancelled", 9, False, True),
        ],
        "transitions": [
            ("open", "preparing", "orders.advance_kitchen"),
            ("preparing", "ready", "orders.advance_kitchen"),
            ("ready", "served", "orders.advance_kitchen"),
            ("open", "cancelled", "orders.cancel"),
            ("preparing", "cancelled", "orders.cancel"),
        ],
    },
    "delivery": {
        "statuses": [
            ("pending", "Pendiente", "new", 1, True, False),
            ("paid", "Pagado", "new", 2, False, False),
            ("preparing", "Preparacion", "kitchen", 3, False, False),
            ("ready", "Listo", "ready", 4, False, False),
            ("in_transit", "En camino", "in_transit", 5, False, False),
            ("delivered", "Entregado", "completed", 6, False, True),
            ("cancelled", "Cancelado", "cancelled", 9, False, True),
        ],
        "transitions": [
            ("pending", "paid", "payments.register"),
            ("paid", "preparing", "orders.advance_kitchen"),
            ("preparing", "ready", "orders.advance_kitchen"),
            ("ready", "in_transit", "delivery.assign"),
            ("in_transit", "delivered", "delivery.complete"),
            ("pending", "cancelled", "orders.cancel"),
            ("paid", "cancelled", "orders.cancel"),
        ],
    },
}


def provision_tenant(db: Session, tenant: Tenant) -> None:
    """Siembra estados, rol admin e impuesto por defecto para un tenant recien creado."""
    preset = STATUS_PRESETS.get(tenant.business_type, STATUS_PRESETS["fast_food"])

    status_by_code: dict[str, OrderStatus] = {}
    for code, name, category, sort_order, is_initial, is_final in preset["statuses"]:
        status = OrderStatus(
            tenant_id=tenant.id,
            code=code,
            name=name,
            category=category,
            sort_order=sort_order,
            is_initial=is_initial,
            is_final=is_final,
        )
        db.add(status)
        status_by_code[code] = status
    db.flush()  # asigna ids antes de crear las transiciones

    for from_code, to_code, permission in preset["transitions"]:
        db.add(
            OrderStatusTransition(
                from_status_id=status_by_code[from_code].id,
                to_status_id=status_by_code[to_code].id,
                required_permission=permission,
            )
        )

    db.add(
        TaxRate(
            tenant_id=tenant.id,
            name="Impuesto general",
            rate=Decimal("0"),
            included_in_price=True,
            is_default=True,
        )
    )

    admin_role = Role(tenant_id=tenant.id, code="admin", name="Administrador", is_system=True)
    admin_role.permissions = list(db.scalars(select(Permission)).all())
    db.add(admin_role)

    db.flush()


def create_tenant(
    db: Session, *, name: str, business_type: str = "fast_food", currency: str = "COP", settings: dict | None = None
) -> Tenant:
    """Crea un tenant y lo deja listo para operar: aplica alta + aprovisionamiento en una sola transaccion."""
    if settings is None:
        settings = defaults_for(business_type)
    else:
        validate(settings)

    tenant = tenant_repository.create(db, name=name, business_type=business_type, currency=currency, settings=settings)
    provision_tenant(db, tenant)
    db.commit()
    db.refresh(tenant)
    return tenant
