from collections.abc import Generator

from sqlalchemy import create_engine, event, text
from sqlalchemy.orm import Session, sessionmaker, DeclarativeBase

from app.core.config import settings

TENANT_KEY = "tenant_id"

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
    """Activa el aislamiento por Row Level Security para esta sesion.

    Debe llamarse en cada request autenticado, antes de cualquier query.
    Sin esto, las politicas RLS de Postgres no devuelven filas.

    Postgres no acepta parametros bindeados en `SET LOCAL` (es un comando,
    no una expresion): hay que usar `set_config`, que si los acepta.

    El tenant queda guardado en la sesion porque `set_config(..., true)` es
    local a la transaccion: al hacer commit se pierde, y la siguiente
    consulta quedaria sin contexto. El listener de abajo lo repone.
    """
    db.info[TENANT_KEY] = tenant_id
    db.execute(text("SELECT set_config('app.current_tenant', :tid, true)"), {"tid": tenant_id})


@event.listens_for(Session, "after_begin")
def _reponer_tenant(session: Session, transaction, connection) -> None:
    """Vuelve a fijar el tenant cada vez que la sesion abre una transaccion.

    Sin esto, cualquier servicio que haga `commit()` y despues vuelva a leer
    —por ejemplo un `refresh()` para devolver lo recien creado— consultaria
    sin contexto y no encontraria ni su propia fila.

    Va sobre `Session` y no sobre `SessionLocal` para que acompañe a cualquier
    sesion que haya pasado por `set_tenant_context`, sin importar como se
    construyo. Las sesiones sin tenant (la administrativa) no se tocan.

    Se usa `connection` y no `session` para no reentrar en este mismo evento.
    """
    tenant_id = session.info.get(TENANT_KEY)
    if tenant_id:
        connection.execute(
            text("SELECT set_config('app.current_tenant', :tid, true)"), {"tid": tenant_id}
        )
