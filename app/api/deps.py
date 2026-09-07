from typing import Annotated

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from jose import JWTError
from sqlalchemy.orm import Session

from app.core.database import get_db, set_tenant_context
from app.core.security import decode_access_token

bearer = HTTPBearer()


class RequestContext:
    """Contexto de la peticion: quien pide, de que empresa y con que permisos."""

    def __init__(self, user_id: str, tenant_id: str, branch_id: str | None,
                 role_code: str, permissions: list[str]):
        self.user_id = user_id
        self.tenant_id = tenant_id
        self.branch_id = branch_id
        self.role_code = role_code
        self.permissions = permissions

    def has(self, permission: str) -> bool:
        return permission in self.permissions


def get_context(
    creds: Annotated[HTTPAuthorizationCredentials, Depends(bearer)],
    db: Annotated[Session, Depends(get_db)],
) -> RequestContext:
    """Punto UNICO donde se resuelve el tenant. No replicar en controladores."""
    try:
        payload = decode_access_token(creds.credentials)
    except JWTError:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Token invalido")

    ctx = RequestContext(
        user_id=payload["sub"],
        tenant_id=payload["tenant_id"],
        branch_id=payload.get("branch_id"),
        role_code=payload.get("role", ""),
        permissions=payload.get("permissions", []),
    )
    # Red de seguridad: aunque un query olvide el WHERE tenant_id,
    # las politicas RLS de Postgres impiden ver datos de otra empresa.
    set_tenant_context(db, ctx.tenant_id)
    return ctx


def require(permission: str):
    """Dependencia de autorizacion: require('orders.cancel')."""

    def checker(ctx: Annotated[RequestContext, Depends(get_context)]) -> RequestContext:
        if not ctx.has(permission):
            raise HTTPException(
                status.HTTP_403_FORBIDDEN, f"Falta el permiso: {permission}"
            )
        return ctx

    return checker
