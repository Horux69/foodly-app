import uuid

from sqlalchemy.orm import Session

from app.core.security import create_access_token, verify_password
from app.domain.tenant_settings import TenantSettings
from app.domain.tenant_settings import parse as parse_settings
from app.models.branch import Branch
from app.models.tenant import Tenant
from app.models.user import User
from app.repositories import branch_repository, tenant_repository, user_repository


class AuthError(Exception):
    pass


def login(db: Session, *, email: str, password: str) -> str:
    """Autentica y emite el JWT. El tenant_id se resuelve aqui, a partir del
    usuario encontrado, y de ahi en adelante viaja siempre dentro del token
    (nunca se vuelve a pedir al cliente, ver deps.py:get_context).
    """
    user = user_repository.get_by_email(db, email)
    if user is None or not verify_password(password, user.password_hash):
        raise AuthError("Credenciales invalidas")

    permissions = [p.code for p in user.role.permissions]
    return create_access_token(
        user_id=str(user.id),
        tenant_id=str(user.tenant_id),
        branch_id=str(user.branch_id) if user.branch_id else None,
        role_code=user.role.code,
        permissions=permissions,
    )


def get_me(
    db: Session, *, tenant_id: uuid.UUID, user_id: uuid.UUID
) -> tuple[User, Branch | None, Tenant, TenantSettings]:
    user = user_repository.get(db, tenant_id, user_id)
    if user is None:
        raise AuthError("El usuario ya no existe")

    tenant = tenant_repository.get(db, tenant_id)
    if tenant is None:
        raise AuthError("El tenant ya no existe")

    branch = branch_repository.get(db, tenant_id, user.branch_id) if user.branch_id else None
    settings = parse_settings(tenant.settings, business_type=tenant.business_type)
    return user, branch, tenant, settings
