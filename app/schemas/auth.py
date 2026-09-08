import uuid

from pydantic import BaseModel


class LoginRequest(BaseModel):
    email: str
    password: str


class LoginResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"


class MeOut(BaseModel):
    """Contexto de operacion del usuario autenticado.

    Va aparte de /settings a proposito: esto es lo que el propio usuario
    necesita para operar (su sucursal, sus permisos, como vende el
    restaurante) y no requiere permisos de administracion para leerse.
    """

    user_id: uuid.UUID
    name: str
    email: str
    role: str
    permissions: list[str]
    branch_id: uuid.UUID | None
    branch_name: str | None
    tenant_name: str
    currency: str
    channels: list[str]
    uses_tables: bool
    asks_tip: bool
