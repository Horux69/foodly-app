"""Infraestructura de los tests de integracion.

Corren contra una base de datos real y aparte (`<db>_test`), no contra la de
desarrollo: se crea desde cero al arrancar la sesion de pytest aplicando el
mismo esquema y semillas que usa produccion, asi que lo que se prueba aqui
es el esquema de verdad y no una aproximacion.

Cada test vive dentro de una transaccion que se revierte al terminar. Los
servicios llaman `db.commit()` por su cuenta, asi que la sesion se abre con
`join_transaction_mode="create_savepoint"`: esos commits se vuelven
savepoints y el rollback exterior los deshace igual. Sin eso cada test
ensuciaria al siguiente.

Si Postgres no esta arriba, los tests se saltan en vez de fallar: `pytest`
sin Docker debe seguir corriendo los tests de dominio.
"""

import uuid
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.database import get_db
from app.core.security import hash_password
from app.main import app
from app.repositories import branch_repository, role_repository, user_repository
from app.services.tenant_provisioning import create_tenant

ROOT = Path(__file__).resolve().parent.parent.parent
MIGRATIONS = sorted((ROOT / "db" / "migrations").glob("*.sql"))
PERMISSIONS_SEED = ROOT / "db" / "seeds" / "001_defaults.sql"

TEST_DB_SUFFIX = "_test"


def _test_database_url() -> str:
    base, _, name = settings.DATABASE_URL.rpartition("/")
    return f"{base}/{name}{TEST_DB_SUFFIX}"


def _recreate_test_database() -> None:
    """Recrea la base de prueba desde cero. Se conecta a `postgres` porque no
    se puede borrar una base estando conectado a ella."""
    base, _, name = settings.DATABASE_URL.rpartition("/")
    admin_engine = create_engine(f"{base}/postgres", isolation_level="AUTOCOMMIT")
    test_db = f"{name}{TEST_DB_SUFFIX}"
    with admin_engine.connect() as conn:
        conn.execute(text(f'DROP DATABASE IF EXISTS "{test_db}" WITH (FORCE)'))
        conn.execute(text(f'CREATE DATABASE "{test_db}"'))
    admin_engine.dispose()


@pytest.fixture(scope="session")
def engine():
    try:
        _recreate_test_database()
    except Exception as exc:  # Postgres apagado: no es una falla del codigo
        pytest.skip(f"Sin base de datos para tests de integracion: {exc}")

    test_engine = create_engine(_test_database_url())
    with test_engine.begin() as conn:
        for migration in MIGRATIONS:
            conn.execute(text(migration.read_text(encoding="utf-8")))
        conn.execute(text(PERMISSIONS_SEED.read_text(encoding="utf-8")))
    yield test_engine
    test_engine.dispose()


@pytest.fixture
def db(engine) -> Session:
    connection = engine.connect()
    transaction = connection.begin()
    session = Session(bind=connection, join_transaction_mode="create_savepoint")
    try:
        yield session
    finally:
        session.close()
        transaction.rollback()
        connection.close()


@pytest.fixture
def client(db) -> TestClient:
    # La app recibe la misma sesion del test y no la cierra: de eso se
    # encarga el fixture, que ademas revierte todo al final.
    app.dependency_overrides[get_db] = lambda: db
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.clear()


@pytest.fixture
def make_tenant(db):
    """Da de alta un restaurante completo, igual que scripts/create_tenant.py."""

    def _make(
        name: str = "Test Resto",
        *,
        business_type: str = "fast_food",
        branch_code: str = "TST",
        email: str = "admin@test.local",
        password: str = "clave-de-prueba",
        permissions: list[str] | None = None,
    ) -> dict:
        tenant = create_tenant(db, name=name, business_type=business_type)
        branch = branch_repository.create(
            db,
            tenant_id=tenant.id,
            name=f"Sede {branch_code}",
            code=branch_code,
            timezone="America/Bogota",
            address=None,
            phone=None,
        )

        if permissions is None:
            role = role_repository.get_by_code(db, tenant.id, "admin")
        else:
            role = role_repository.create(
                db, tenant_id=tenant.id, code=f"rol-{uuid.uuid4().hex[:6]}", name="Rol de prueba"
            )
            role.permissions = role_repository.permissions_by_codes(db, permissions)

        user = user_repository.create(
            db,
            tenant_id=tenant.id,
            role_id=role.id,
            branch_id=branch.id,
            name="Usuario de prueba",
            email=email,
            password_hash=hash_password(password),
        )
        db.commit()
        return {
            "tenant": tenant,
            "branch": branch,
            "role": role,
            "user": user,
            "email": email,
            "password": password,
        }

    return _make


@pytest.fixture
def auth(client):
    """Devuelve la cabecera Authorization de un usuario ya creado."""

    def _auth(email: str, password: str) -> dict:
        response = client.post("/api/v1/auth/login", json={"email": email, "password": password})
        assert response.status_code == 200, response.text
        return {"Authorization": f"Bearer {response.json()['access_token']}"}

    return _auth


@pytest.fixture
def demo(db, make_tenant):
    """Restaurante con menu listo para pedir: una categoria y un producto."""
    from decimal import Decimal

    from app.repositories import menu_repository, tax_rate_repository

    data = make_tenant()
    tenant = data["tenant"]

    tax = tax_rate_repository.create(
        db,
        tenant_id=tenant.id,
        name="Impoconsumo 8%",
        rate=Decimal("0.08"),
        included_in_price=True,
        is_default=False,
    )
    category = menu_repository.create_category(db, tenant_id=tenant.id, name="Hamburguesas")
    item = menu_repository.create_item(
        db,
        category_id=category.id,
        name="Clasica",
        base_price=Decimal("18000"),
        tax_rate_id=tax.id,
    )
    db.commit()

    data.update({"tax": tax, "category": category, "item": item})
    return data
