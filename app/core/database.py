from collections.abc import Generator

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, sessionmaker, DeclarativeBase

from app.core.config import settings

engine = create_engine(settings.APP_DATABASE_URL or settings.DATABASE_URL, pool_pre_ping=True)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False)

# Conexion administrativa, dueña de las tablas. Solo para lo que es
# inherentemente previo a un tenant: migraciones y alta de empresas. Nunca
# para servir una request, que debe quedar sujeta a las politicas RLS.
admin_engine = create_engine(settings.DATABASE_URL, pool_pre_ping=True)
AdminSessionLocal = sessionmaker(bind=admin_engine, autoflush=False, autocommit=False)


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

    Postgres no acepta parametros bindeados en `SET LOCAL` (es un comando,
    no una expresion): hay que usar `set_config`, que si los acepta.
    """
    db.execute(text("SELECT set_config('app.current_tenant', :tid, true)"), {"tid": tenant_id})
