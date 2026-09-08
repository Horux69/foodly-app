"""Casos de uso de administracion: configuracion, sucursales, usuarios y roles.

Todo lo que aqui se crea queda amarrado al tenant del token. Los ids que
llegan del cliente (rol, sucursal) se verifican contra ese tenant antes de
usarse: asignar un rol de otra empresa seria una escalada de privilegios.
"""

import uuid
from decimal import Decimal

from sqlalchemy.orm import Session

from app.core.security import hash_password
from app.domain.tenant_settings import SettingsError, TenantSettings, parse, validate
from app.models.branch import Branch
from app.models.role import Role
from app.models.table import Table
from app.models.tax_rate import TaxRate
from app.models.tenant import Tenant
from app.models.user import User
from app.repositories import (
    branch_repository,
    role_repository,
    table_repository,
    tax_rate_repository,
    tenant_repository,
    user_repository,
)


class AdminError(Exception):
    pass


def _tenant(db: Session, tenant_id: uuid.UUID) -> Tenant:
    tenant = tenant_repository.get(db, tenant_id)
    if tenant is None:
        raise AdminError("El tenant no existe")
    return tenant


# ---------- Configuracion (modulo 9) ----------


def get_settings(db: Session, *, tenant_id: uuid.UUID) -> tuple[Tenant, TenantSettings]:
    tenant = _tenant(db, tenant_id)
    return tenant, parse(tenant.settings, business_type=tenant.business_type)


def update_settings(db: Session, *, tenant_id: uuid.UUID, changes: dict) -> tuple[Tenant, TenantSettings]:
    tenant = _tenant(db, tenant_id)

    # Se valida el resultado del merge y no solo el parche: activar el canal
    # 'table' sin tocar uses_tables debe chocar contra el valor ya guardado.
    merged = dict(tenant.settings or {}) | changes
    try:
        validate(merged)
    except SettingsError as exc:
        raise AdminError(str(exc)) from exc

    tenant.settings = merged
    db.commit()
    db.refresh(tenant)
    return tenant, parse(tenant.settings, business_type=tenant.business_type)


# ---------- Sucursales ----------


def list_branches(db: Session, *, tenant_id: uuid.UUID) -> list[Branch]:
    return branch_repository.list_for_tenant(db, tenant_id)


def create_branch(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    name: str,
    code: str,
    timezone: str = "America/Bogota",
    address: str | None = None,
    phone: str | None = None,
) -> Branch:
    if branch_repository.get_by_code(db, tenant_id, code) is not None:
        raise AdminError(f"Ya existe una sucursal con el código '{code}'")

    branch = branch_repository.create(
        db, tenant_id=tenant_id, name=name, code=code, timezone=timezone, address=address, phone=phone
    )
    db.commit()
    db.refresh(branch)
    return branch


def set_branch_active(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID, is_active: bool) -> Branch:
    branch = branch_repository.get(db, tenant_id, branch_id)
    if branch is None:
        raise AdminError("La sucursal no existe para este tenant")
    branch.is_active = is_active
    db.commit()
    db.refresh(branch)
    return branch


# ---------- Impuestos ----------


def list_tax_rates(db: Session, *, tenant_id: uuid.UUID) -> list[TaxRate]:
    return tax_rate_repository.list_for_tenant(db, tenant_id)


def create_tax_rate(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    name: str,
    rate: Decimal,
    included_in_price: bool = True,
    is_default: bool = False,
) -> TaxRate:
    if is_default:
        tax_rate_repository.clear_default(db, tenant_id)

    tax_rate = tax_rate_repository.create(
        db,
        tenant_id=tenant_id,
        name=name,
        rate=rate,
        included_in_price=included_in_price,
        is_default=is_default,
    )
    db.commit()
    db.refresh(tax_rate)
    return tax_rate


def set_default_tax_rate(db: Session, *, tenant_id: uuid.UUID, tax_rate_id: uuid.UUID) -> TaxRate:
    tax_rate = tax_rate_repository.get(db, tenant_id, tax_rate_id)
    if tax_rate is None:
        raise AdminError("El impuesto no existe para este tenant")

    tax_rate_repository.clear_default(db, tenant_id)
    tax_rate.is_default = True
    db.commit()
    db.refresh(tax_rate)
    return tax_rate


# ---------- Mesas ----------


def _owned_branch(db: Session, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> Branch:
    branch = branch_repository.get(db, tenant_id, branch_id)
    if branch is None:
        raise AdminError("La sucursal no existe para este tenant")
    return branch


def list_tables(db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID) -> list[Table]:
    branch = _owned_branch(db, tenant_id, branch_id)
    return table_repository.list_for_branch(db, branch.id)


def create_table(
    db: Session, *, tenant_id: uuid.UUID, branch_id: uuid.UUID, code: str, capacity: int
) -> Table:
    branch = _owned_branch(db, tenant_id, branch_id)

    tenant = _tenant(db, tenant_id)
    settings = parse(tenant.settings, business_type=tenant.business_type)
    if not settings.uses_tables:
        raise AdminError("Este restaurante no maneja mesas: actívalo en la configuración")

    if table_repository.get_by_code(db, branch_id=branch.id, code=code) is not None:
        raise AdminError(f"Ya existe una mesa con el código '{code}' en esta sucursal")

    table = table_repository.create(db, branch_id=branch.id, code=code, capacity=capacity)
    db.commit()
    db.refresh(table)
    return table


# ---------- Roles ----------


def list_roles(db: Session, *, tenant_id: uuid.UUID) -> list[Role]:
    return role_repository.list_for_tenant(db, tenant_id)


def _resolve_permissions(db: Session, codes: list[str]):
    permissions = role_repository.permissions_by_codes(db, codes)
    found = {p.code for p in permissions}
    unknown = [c for c in codes if c not in found]
    if unknown:
        raise AdminError(f"Permisos desconocidos: {unknown}")
    return permissions


def create_role(db: Session, *, tenant_id: uuid.UUID, code: str, name: str, permissions: list[str]) -> Role:
    if role_repository.get_by_code(db, tenant_id, code) is not None:
        raise AdminError(f"Ya existe un rol con el código '{code}'")

    role = role_repository.create(db, tenant_id=tenant_id, code=code, name=name)
    role.permissions = _resolve_permissions(db, permissions)
    db.commit()
    db.refresh(role)
    return role


def set_role_permissions(
    db: Session, *, tenant_id: uuid.UUID, role_id: uuid.UUID, permissions: list[str]
) -> Role:
    role = role_repository.get(db, tenant_id, role_id)
    if role is None:
        raise AdminError("El rol no existe para este tenant")
    if role.is_system:
        # El rol admin es la salida de emergencia del tenant: si se le quitan
        # permisos, nadie queda con users.manage para devolverselos.
        raise AdminError("Los roles de sistema no se pueden modificar")

    role.permissions = _resolve_permissions(db, permissions)
    db.commit()
    db.refresh(role)
    return role


# ---------- Usuarios ----------


def list_users(db: Session, *, tenant_id: uuid.UUID) -> list[User]:
    return user_repository.list_for_tenant(db, tenant_id)


def create_user(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    name: str,
    email: str,
    password: str,
    role_id: uuid.UUID,
    branch_id: uuid.UUID | None = None,
) -> User:
    if user_repository.get_by_email_in_tenant(db, tenant_id, email) is not None:
        raise AdminError(f"Ya existe un usuario con el email '{email}'")

    if role_repository.get(db, tenant_id, role_id) is None:
        raise AdminError("El rol no existe para este tenant")

    if branch_id is not None and branch_repository.get(db, tenant_id, branch_id) is None:
        raise AdminError("La sucursal no existe para este tenant")

    user = user_repository.create(
        db,
        tenant_id=tenant_id,
        role_id=role_id,
        branch_id=branch_id,
        name=name,
        email=email,
        password_hash=hash_password(password),
    )
    db.commit()
    db.refresh(user)
    return user


def set_user_active(db: Session, *, tenant_id: uuid.UUID, user_id: uuid.UUID, is_active: bool) -> User:
    user = user_repository.get(db, tenant_id, user_id)
    if user is None:
        raise AdminError("El usuario no existe para este tenant")
    user.is_active = is_active
    db.commit()
    db.refresh(user)
    return user
