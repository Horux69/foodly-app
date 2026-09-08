"""Aislamiento entre empresas.

Es la propiedad de seguridad central del proyecto: el `tenant_id` sale solo
del token y ningun id que mande el cliente puede sacar datos de otra
empresa. Cada test aqui intenta cruzar esa frontera a proposito.
"""

from decimal import Decimal

import pytest

from app.repositories import menu_repository


@pytest.fixture
def dos_restaurantes(db, make_tenant):
    a = make_tenant(name="Resto A", branch_code="AAA", email="a@test.local")
    b = make_tenant(name="Resto B", branch_code="BBB", email="b@test.local")

    for data, nombre, precio in ((a, "Plato A", "10000"), (b, "Plato B", "20000")):
        category = menu_repository.create_category(db, tenant_id=data["tenant"].id, name="Cat")
        data["item"] = menu_repository.create_item(
            db, category_id=category.id, name=nombre, base_price=Decimal(precio)
        )
    db.commit()
    return a, b


def _crear_pedido(client, headers, item_id):
    response = client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(item_id), "quantity": 1}]},
    )
    assert response.status_code == 201, response.text
    return response.json()


def test_el_menu_solo_muestra_lo_propio(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    menu_a = client.get("/api/v1/menu", headers=auth(a["email"], a["password"])).json()

    nombres = [item["name"] for cat in menu_a for item in cat["items"]]
    assert nombres == ["Plato A"]


def test_no_se_puede_pedir_un_producto_de_otra_empresa(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    response = client.post(
        "/api/v1/orders",
        headers=auth(a["email"], a["password"]),
        json={"channel": "counter", "items": [{"menu_item_id": str(b["item"].id), "quantity": 1}]},
    )
    assert response.status_code == 422
    assert "no existe" in response.json()["detail"]


def test_los_pedidos_ajenos_no_aparecen_ni_se_consultan(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    headers_a = auth(a["email"], a["password"])
    headers_b = auth(b["email"], b["password"])

    pedido_b = _crear_pedido(client, headers_b, b["item"].id)

    listado_a = client.get("/api/v1/orders", headers=headers_a).json()
    assert listado_a == []

    # Ni siquiera sabiendo el id exacto
    assert client.get(f"/api/v1/orders/{pedido_b['id']}", headers=headers_a).status_code == 404


def test_no_se_puede_cambiar_el_estado_de_un_pedido_ajeno(client, auth, dos_restaurantes, db):
    a, b = dos_restaurantes
    headers_a = auth(a["email"], a["password"])
    pedido_b = _crear_pedido(client, auth(b["email"], b["password"]), b["item"].id)

    from app.repositories import order_status_repository

    estado_a = order_status_repository.get_initial(db, a["tenant"].id)
    response = client.post(
        f"/api/v1/orders/{pedido_b['id']}/status",
        headers=headers_a,
        json={"to_status_id": str(estado_a.id)},
    )
    assert response.status_code == 422
    assert "no encontrado" in response.json()["detail"]


def test_no_se_puede_cobrar_un_pedido_ajeno(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    pedido_b = _crear_pedido(client, auth(b["email"], b["password"]), b["item"].id)

    response = client.post(
        f"/api/v1/orders/{pedido_b['id']}/payments",
        headers=auth(a["email"], a["password"]),
        json={"method": "cash", "amount": 20000},
    )
    assert response.status_code == 422


def test_no_se_puede_asignar_un_rol_de_otra_empresa(client, auth, dos_restaurantes):
    """El caso mas grave: asignar el rol admin de otra empresa seria una
    escalada de privilegios que ademas cruza el tenant."""
    a, b = dos_restaurantes
    response = client.post(
        "/api/v1/users",
        headers=auth(a["email"], a["password"]),
        json={
            "name": "Intruso",
            "email": "intruso@test.local",
            "password": "clave-larga-123",
            "role_id": str(b["role"].id),
        },
    )
    assert response.status_code == 422
    assert "rol no existe" in response.json()["detail"].lower()


def test_no_se_puede_usar_una_sucursal_de_otra_empresa(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    headers_a = auth(a["email"], a["password"])

    override = client.put(
        f"/api/v1/menu/items/{a['item'].id}/branch-override",
        headers=headers_a,
        json={"branch_id": str(b["branch"].id), "price": 5000},
    )
    # 404 y no 422: la sucursal ajena se trata como inexistente, que ademas
    # evita confirmarle a quien pregunta que existe en otra empresa.
    assert override.status_code == 404

    reporte = client.get(
        f"/api/v1/reports/sales?branch_id={b['branch'].id}", headers=headers_a
    )
    assert reporte.status_code == 422


def test_las_sucursales_y_usuarios_listados_son_solo_los_propios(client, auth, dos_restaurantes):
    a, b = dos_restaurantes
    headers_a = auth(a["email"], a["password"])

    codigos = [s["code"] for s in client.get("/api/v1/branches", headers=headers_a).json()]
    assert codigos == ["AAA"]

    correos = [u["email"] for u in client.get("/api/v1/users", headers=headers_a).json()]
    assert correos == ["a@test.local"]


def test_los_reportes_no_suman_ventas_ajenas(client, auth, dos_restaurantes, db):
    a, b = dos_restaurantes
    headers_b = auth(b["email"], b["password"])
    _crear_pedido(client, headers_b, b["item"].id)

    ventas_a = client.get("/api/v1/reports/sales", headers=auth(a["email"], a["password"])).json()
    assert ventas_a["totals"]["orders"] == 0
    assert Decimal(ventas_a["totals"]["revenue"]) == Decimal("0")
