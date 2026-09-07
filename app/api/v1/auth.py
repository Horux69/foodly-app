from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.schemas.auth import LoginRequest, LoginResponse
from app.services.auth import AuthError, login

router = APIRouter()


@router.post("/login", response_model=LoginResponse)
def login_endpoint(payload: LoginRequest, db: Session = Depends(get_db)) -> LoginResponse:
    try:
        token = login(db, email=payload.email, password=payload.password)
    except AuthError:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Credenciales invalidas")
    return LoginResponse(access_token=token)
