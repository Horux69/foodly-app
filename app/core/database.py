from collections.abc import Generator

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, sessionmaker, DeclarativeBase

from app.core.config import settings

engine = create_engine(settings.DATABASE_URL, pool_pre_ping=True)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False)


class Base(DeclarativeBase):
    pass


def get_db() -> Generator[Session, None, None]:
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def set_tenant_context(db: Session, tenant_id: str) -> None:
    """Activa el aislamiento por Row Level Security para la transaccion actual.

    Debe llamarse en cada request autenticado, antes de cualquier query.
    Sin esto, las politicas RLS de Postgres no devuelven filas.
    """
    db.execute(text("SET LOCAL app.current_tenant = :tid"), {"tid": tenant_id})
