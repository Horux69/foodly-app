"""Row Level Security: que la base aisle por su cuenta.

El resto de la suite comprueba que las consultas filtran bien por tenant_id.
Esto comprueba lo otro: que si una consulta algun dia olvidara el filtro, el
motor tampoco devolveria datos ajenos.

Las consultas corren con `SET LOCAL ROLE resto_app`, el rol con el que la
aplicacion sirve requests. Esa distincion es todo el punto: Postgres saltea
las politicas para el dueño de las tablas, asi que una prueba hecha con el
usuario administrativo pasaria sin probar nada. Cambiar de rol dentro de la
misma transaccion, en vez de abrir otra conexion, permite ademas ver los
datos que armo el fixture sin tener que confirmarlos.
"""

from contextlib import contextmanager
from decimal import Decimal

import pytest
from sqlalchemy import text

from app.repositories import menu_repository

TABLAS_CON_TENANT = [
    "tenants",
    "branches",
    "roles",
    "users",
    "tax_rates",
    "order_statuses",
    "menu_categories",
    "menu_items",
]

TABLAS_HIJAS = ["order_items", "payments", "order_status_history", "modifiers", "tables"]


@pytest.fixture(autouse=True)
def _exige_rol_de_aplicacion(db):
    existe = db.execute(text("SELECT 1 FROM pg_roles WHERE rolname = 'resto_app'")).scalar()
    if not existe:
        pytest.skip("Falta el rol resto_app: correr db/migrations/002_rls_hardening.sql")


@contextmanager
def como_app(db, tenant_id=None):
    """Corre lo de adentro con el rol y el contexto de la aplicacion."""
    db.execute(text("SET LOCAL ROLE resto_app"))
    db.execute(
        text("SELECT set_config('app.current_tenant', :t, true)"),
        {"t": str(tenant_id) if tenant_id else ""},
    )
    try:
        yield
    finally:
        db.execute(text("RESET ROLE"))


def contar(db, tabla, tenant_id=None) -> int:
    with como_app(db, tenant_id):
        return db.execute(text(f"SELECT count(*) FROM {tabla}")).scalar()


@pytest.fixture
def datos(db, make_tenant):
    """Dos empresas con datos propios, creadas con el rol administrativo."""
    a = make_tenant(name="RLS A", branch_code="RLA", email="rls-a@test.local")
    b = make_tenant(name="RLS B", branch_code="RLB", email="rls-b@test.local")
    for data, nombre in ((a, "Plato A"), (b, "Plato B")):
        categoria = menu_repository.create_category(db, tenant_id=data["tenant"].id, name="Cat")
        menu_repository.create_item(
            db, category_id=categoria.id, name=nombre, base_price=Decimal("1000")
        )
    db.flush()
    return a, b


@pytest.mark.parametrize("tabla", TABLAS_CON_TENANT)
def test_sin_contexto_de_empresa_no_se_ve_nada(db, datos, tabla):
    """Una consulta que olvide poner el tenant no devuelve filas, en vez de
    devolverlas todas."""
    assert contar(db, tabla) == 0


@pytest.mark.parametrize("tabla", TABLAS_CON_TENANT)
def test_con_contexto_solo_se_ve_lo_propio(db, datos, tabla):
    a, b = datos
    como_dueña = db.execute(text(f"SELECT count(*) FROM {tabla}")).scalar()
    de_a = contar(db, tabla, a["tenant"].id)
    de_b = contar(db, tabla, b["tenant"].id)

    assert de_a > 0 and de_b > 0
    # Lo que ve cada una es una parte de lo que hay, no el total
    assert de_a < como_dueña and de_b < como_dueña


def test_una_consulta_sin_filtro_no_alcanza_al_vecino(db, datos):
    """El caso que motiva todo esto: un SELECT que olvido el WHERE."""
    a, _ = datos
    with como_app(db, a["tenant"].id):
        nombres = db.execute(text("SELECT name FROM menu_items")).scalars().all()
    assert nombres == ["Plato A"]


def test_no_se_puede_leer_una_empresa_ajena_ni_nombrandola(db, datos):
    a, b = datos
    with como_app(db, a["tenant"].id):
        filas = (
            db.execute(text("SELECT name FROM tenants WHERE id = :otro"), {"otro": b["tenant"].id})
            .scalars()
            .all()
        )
    assert filas == []


def test_no_se_puede_insertar_en_otra_empresa(db, datos):
    """USING gobierna tambien el INSERT: no alcanza con escribir el tenant_id
    ajeno en la fila."""
    a, b = datos
    # Sin como_app: el rechazo aborta la transaccion, y hay que deshacer el
    # savepoint antes de poder volver al rol administrativo.
    savepoint = db.begin_nested()
    db.execute(text("SET LOCAL ROLE resto_app"))
    db.execute(
        text("SELECT set_config('app.current_tenant', :t, true)"), {"t": str(a["tenant"].id)}
    )

    with pytest.raises(Exception) as error:
        db.execute(
            text("INSERT INTO menu_categories (tenant_id, name) VALUES (:otro, 'Intruso')"),
            {"otro": b["tenant"].id},
        )
    assert "row-level security" in str(error.value).lower()

    savepoint.rollback()
    db.execute(text("RESET ROLE"))


def test_las_tablas_hijas_tambien_estan_cubiertas(db, datos):
    """order_items y payments no tienen tenant_id: cuelgan del pedido."""
    for tabla in TABLAS_HIJAS:
        assert contar(db, tabla) == 0, tabla


def test_el_catalogo_de_permisos_es_comun_a_todos(db, datos):
    """`permissions` queda a proposito fuera de RLS: es de la plataforma, no
    de ninguna empresa, y sin el no se pueden armar roles."""
    assert contar(db, "permissions") > 0


def test_la_funcion_de_login_solo_revela_el_tenant(db, datos):
    """La unica excepcion es deliberadamente pobre: entra un email, sale un
    tenant_id, nada mas."""
    a, _ = datos
    with como_app(db):
        encontrado = db.execute(
            text("SELECT auth_tenant_for_email(:email)"), {"email": a["email"]}
        ).scalar()
        inexistente = db.execute(
            text("SELECT auth_tenant_for_email(:email)"), {"email": "nadie@test.local"}
        ).scalar()

    assert encontrado == a["tenant"].id
    assert inexistente is None


def test_el_login_por_api_sigue_funcionando_bajo_rls(client, datos):
    """La funcion de escape existe para esto: sin ella el login se quedaria
    sin filas y nadie podria entrar."""
    a, _ = datos
    response = client.post(
        "/api/v1/auth/login", json={"email": a["email"], "password": a["password"]}
    )
    assert response.status_code == 200
