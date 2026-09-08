import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, require
from app.core.database import get_db
from app.models.role import Role
from app.models.user import User
from app.repositories import role_repository
from app.schemas.admin import (
    ActiveUpdate,
    BranchCreate,
    BranchOut,
    PermissionOut,
    RoleCreate,
    RoleOut,
    RolePermissionsUpdate,
    TableCreate,
    TableOut,
    TaxRateCreate,
    TaxRateOut,
    TenantSettingsOut,
    TenantSettingsUpdate,
    UserCreate,
    UserOut,
)
from app.services import admin_service
from app.services.admin_service import AdminError

router = APIRouter()


def _role_out(role: Role) -> RoleOut:
    return RoleOut(
        id=role.id,
        code=role.code,
        name=role.name,
        is_system=role.is_system,
        permissions=sorted(p.code for p in role.permissions),
    )


def _user_out(user: User) -> UserOut:
    return UserOut(
        id=user.id,
        name=user.name,
        email=user.email,
        role_id=user.role_id,
        role_code=user.role.code,
        branch_id=user.branch_id,
        is_active=user.is_active,
    )


# ---------- Configuracion ----------


@router.get("/settings", response_model=TenantSettingsOut, tags=["configuracion"])
def get_settings_endpoint(
    ctx: Annotated[RequestContext, Depends(require("settings.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> TenantSettingsOut:
    tenant, settings = admin_service.get_settings(db, tenant_id=uuid.UUID(ctx.tenant_id))
    return TenantSettingsOut(
        tenant_id=tenant.id,
        name=tenant.name,
        business_type=tenant.business_type,
        currency=tenant.currency,
        channels=list(settings.channels),
        uses_tables=settings.uses_tables,
        asks_tip=settings.asks_tip,
    )


@router.patch("/settings", response_model=TenantSettingsOut, tags=["configuracion"])
def update_settings_endpoint(
    payload: TenantSettingsUpdate,
    ctx: Annotated[RequestContext, Depends(require("settings.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> TenantSettingsOut:
    changes = payload.model_dump(exclude_none=True)
    try:
        tenant, settings = admin_service.update_settings(
            db, tenant_id=uuid.UUID(ctx.tenant_id), changes=changes
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return TenantSettingsOut(
        tenant_id=tenant.id,
        name=tenant.name,
        business_type=tenant.business_type,
        currency=tenant.currency,
        channels=list(settings.channels),
        uses_tables=settings.uses_tables,
        asks_tip=settings.asks_tip,
    )


# ---------- Sucursales ----------


@router.get("/branches", response_model=list[BranchOut], tags=["sucursales"])
def list_branches_endpoint(
    ctx: Annotated[RequestContext, Depends(require("settings.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[BranchOut]:
    branches = admin_service.list_branches(db, tenant_id=uuid.UUID(ctx.tenant_id))
    return [BranchOut.model_validate(b) for b in branches]


@router.post("/branches", response_model=BranchOut, status_code=status.HTTP_201_CREATED, tags=["sucursales"])
def create_branch_endpoint(
    payload: BranchCreate,
    ctx: Annotated[RequestContext, Depends(require("branches.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> BranchOut:
    try:
        branch = admin_service.create_branch(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            name=payload.name,
            code=payload.code,
            timezone=payload.timezone,
            address=payload.address,
            phone=payload.phone,
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return BranchOut.model_validate(branch)


@router.patch("/branches/{branch_id}/active", response_model=BranchOut, tags=["sucursales"])
def set_branch_active_endpoint(
    branch_id: uuid.UUID,
    payload: ActiveUpdate,
    ctx: Annotated[RequestContext, Depends(require("branches.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> BranchOut:
    try:
        branch = admin_service.set_branch_active(
            db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id, is_active=payload.is_active
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return BranchOut.model_validate(branch)


# ---------- Impuestos ----------


@router.get("/tax-rates", response_model=list[TaxRateOut], tags=["configuracion"])
def list_tax_rates_endpoint(
    ctx: Annotated[RequestContext, Depends(require("settings.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[TaxRateOut]:
    rates = admin_service.list_tax_rates(db, tenant_id=uuid.UUID(ctx.tenant_id))
    return [TaxRateOut.model_validate(r) for r in rates]


@router.post(
    "/tax-rates", response_model=TaxRateOut, status_code=status.HTTP_201_CREATED, tags=["configuracion"]
)
def create_tax_rate_endpoint(
    payload: TaxRateCreate,
    ctx: Annotated[RequestContext, Depends(require("settings.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> TaxRateOut:
    try:
        tax_rate = admin_service.create_tax_rate(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            name=payload.name,
            rate=payload.rate,
            included_in_price=payload.included_in_price,
            is_default=payload.is_default,
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return TaxRateOut.model_validate(tax_rate)


@router.put("/tax-rates/{tax_rate_id}/default", response_model=TaxRateOut, tags=["configuracion"])
def set_default_tax_rate_endpoint(
    tax_rate_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("settings.edit"))],
    db: Annotated[Session, Depends(get_db)],
) -> TaxRateOut:
    try:
        tax_rate = admin_service.set_default_tax_rate(
            db, tenant_id=uuid.UUID(ctx.tenant_id), tax_rate_id=tax_rate_id
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return TaxRateOut.model_validate(tax_rate)


# ---------- Mesas ----------


@router.get("/branches/{branch_id}/tables", response_model=list[TableOut], tags=["sucursales"])
def list_tables_endpoint(
    branch_id: uuid.UUID,
    ctx: Annotated[RequestContext, Depends(require("settings.view"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[TableOut]:
    try:
        tables = admin_service.list_tables(db, tenant_id=uuid.UUID(ctx.tenant_id), branch_id=branch_id)
    except AdminError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return [TableOut.model_validate(t) for t in tables]


@router.post(
    "/branches/{branch_id}/tables",
    response_model=TableOut,
    status_code=status.HTTP_201_CREATED,
    tags=["sucursales"],
)
def create_table_endpoint(
    branch_id: uuid.UUID,
    payload: TableCreate,
    ctx: Annotated[RequestContext, Depends(require("branches.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> TableOut:
    try:
        table = admin_service.create_table(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            branch_id=branch_id,
            code=payload.code,
            capacity=payload.capacity,
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return TableOut.model_validate(table)


# ---------- Roles y permisos ----------


@router.get("/permissions", response_model=list[PermissionOut], tags=["roles"])
def list_permissions_endpoint(
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[PermissionOut]:
    return [PermissionOut.model_validate(p) for p in role_repository.list_permissions(db)]


@router.get("/roles", response_model=list[RoleOut], tags=["roles"])
def list_roles_endpoint(
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[RoleOut]:
    return [_role_out(r) for r in admin_service.list_roles(db, tenant_id=uuid.UUID(ctx.tenant_id))]


@router.post("/roles", response_model=RoleOut, status_code=status.HTTP_201_CREATED, tags=["roles"])
def create_role_endpoint(
    payload: RoleCreate,
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> RoleOut:
    try:
        role = admin_service.create_role(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            code=payload.code,
            name=payload.name,
            permissions=payload.permissions,
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _role_out(role)


@router.put("/roles/{role_id}/permissions", response_model=RoleOut, tags=["roles"])
def set_role_permissions_endpoint(
    role_id: uuid.UUID,
    payload: RolePermissionsUpdate,
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> RoleOut:
    try:
        role = admin_service.set_role_permissions(
            db, tenant_id=uuid.UUID(ctx.tenant_id), role_id=role_id, permissions=payload.permissions
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _role_out(role)


# ---------- Usuarios ----------


@router.get("/users", response_model=list[UserOut], tags=["usuarios"])
def list_users_endpoint(
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> list[UserOut]:
    return [_user_out(u) for u in admin_service.list_users(db, tenant_id=uuid.UUID(ctx.tenant_id))]


@router.post("/users", response_model=UserOut, status_code=status.HTTP_201_CREATED, tags=["usuarios"])
def create_user_endpoint(
    payload: UserCreate,
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> UserOut:
    try:
        user = admin_service.create_user(
            db,
            tenant_id=uuid.UUID(ctx.tenant_id),
            name=payload.name,
            email=payload.email,
            password=payload.password,
            role_id=payload.role_id,
            branch_id=payload.branch_id,
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc
    return _user_out(user)


@router.patch("/users/{user_id}/active", response_model=UserOut, tags=["usuarios"])
def set_user_active_endpoint(
    user_id: uuid.UUID,
    payload: ActiveUpdate,
    ctx: Annotated[RequestContext, Depends(require("users.manage"))],
    db: Annotated[Session, Depends(get_db)],
) -> UserOut:
    try:
        user = admin_service.set_user_active(
            db, tenant_id=uuid.UUID(ctx.tenant_id), user_id=user_id, is_active=payload.is_active
        )
    except AdminError as exc:
        raise HTTPException(status.HTTP_404_NOT_FOUND, str(exc)) from exc
    return _user_out(user)
