import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.api.deps import RequestContext, get_context
from app.core.database import get_db
from app.schemas.auth import LoginRequest, LoginResponse, MeOut
from app.services.auth import AuthError, get_me, login

router = APIRouter()


@router.post("/login", response_model=LoginResponse)
def login_endpoint(payload: LoginRequest, db: Session = Depends(get_db)) -> LoginResponse:
    try:
        token = login(db, email=payload.email, password=payload.password)
    except AuthError:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Credenciales inválidas")
    return LoginResponse(access_token=token)


@router.get("/me", response_model=MeOut)
def me_endpoint(
    ctx: Annotated[RequestContext, Depends(get_context)],
    db: Annotated[Session, Depends(get_db)],
) -> MeOut:
    try:
        user, branch, tenant, settings = get_me(
            db, tenant_id=uuid.UUID(ctx.tenant_id), user_id=uuid.UUID(ctx.user_id)
        )
    except AuthError as exc:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, str(exc)) from exc

    return MeOut(
        user_id=user.id,
        name=user.name,
        email=user.email,
        role=user.role.code,
        # Los permisos salen del token y no del rol en base: son los que la
        # sesion actual realmente lleva. Si el rol cambio, se aplican al
        # renovar el token, no a mitad de sesion.
        permissions=ctx.permissions,
        branch_id=branch.id if branch else None,
        branch_name=branch.name if branch else None,
        tenant_name=tenant.name,
        currency=tenant.currency,
        channels=list(settings.channels),
        uses_tables=settings.uses_tables,
        asks_tip=settings.asks_tip,
    )
