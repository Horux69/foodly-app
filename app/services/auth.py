from sqlalchemy.orm import Session

from app.core.security import create_access_token, verify_password
from app.repositories import user_repository


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
