"""Ciclo de vida del pedido: cocina, caja y la regla que las cruza.

Todo se valida contra la maquina de estados que el tenant tiene configurada,
nunca contra codigos escritos en el codigo fuente.
"""

from decimal import Decimal

import pytest

from app.repositories import order_status_repository


@pytest.fixture
def headers(client, demo, auth):
    return auth(demo["email"], demo["password"])


@pytest.fixture
def estados(db, demo):
    return {
        s.code: s for s in order_status_repository.list_statuses(db, demo["tenant"].id)
    }


def _pedido(client, headers, demo, cantidad=1):
    response = client.post(
        "/api/v1/orders",
        headers=headers,
        json={
            "channel": "counter",
            "items": [{"menu_item_id": str(demo["item"].id), "quantity": cantidad}],
        },
    )
    assert response.status_code == 201
    return response.json()


def _avanzar(client, headers, order_id, status_id):
    return client.post(
        f"/api/v1/orders/{order_id}/status",
        headers=headers,
        json={"to_status_id": str(status_id)},
    )


def test_el_pedido_nace_en_el_estado_inicial(client, db, demo, headers):
    pedido = _pedido(client, headers, demo)
    inicial = order_status_repository.get_initial(db, demo["tenant"].id)

    siguientes = client.get(
        f"/api/v1/orders/{pedido['id']}/next-statuses", headers=headers
    ).json()
    codigos = sorted(s["code"] for s in siguientes)
    assert inicial.code == "pending"
    assert codigos == ["cancelled", "paid"]


def test_una_transicion_no_configurada_se_rechaza(client, demo, headers, estados):
    pedido = _pedido(client, headers, demo)
    # pending -> ready no existe en el preset de comida rapida
    response = _avanzar(client, headers, pedido["id"], estados["ready"].id)
    assert response.status_code == 422
    assert "no permitida" in response.json()["detail"]


def test_el_flujo_completo_avanza_y_queda_auditado(client, db, demo, headers, estados):
    pedido = _pedido(client, headers, demo)

    for code in ("paid", "preparing", "ready"):
        assert _avanzar(client, headers, pedido["id"], estados[code].id).status_code == 200

    client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "cash", "amount": 18000},
    )
    assert _avanzar(client, headers, pedido["id"], estados["delivered"].id).status_code == 200

    # El historial es lo que despues alimenta los tiempos de cocina
    from app.models.order import OrderStatusHistory
    from sqlalchemy import select

    historial = db.scalars(
        select(OrderStatusHistory).where(OrderStatusHistory.order_id == pedido["id"])
    ).all()
    assert len(historial) == 5


def test_un_estado_final_no_admite_mas_avances(client, demo, headers, estados):
    pedido = _pedido(client, headers, demo)
    _avanzar(client, headers, pedido["id"], estados["cancelled"].id)

    response = _avanzar(client, headers, pedido["id"], estados["paid"].id)
    assert response.status_code == 422
    assert "final" in response.json()["detail"]


def test_no_se_completa_un_pedido_sin_saldar(client, demo, headers, estados):
    """La regla que cruza cocina y caja."""
    pedido = _pedido(client, headers, demo)
    for code in ("paid", "preparing", "ready"):
        _avanzar(client, headers, pedido["id"], estados[code].id)

    response = _avanzar(client, headers, pedido["id"], estados["delivered"].id)
    assert response.status_code == 422
    assert "no está saldado" in response.json()["detail"]


def test_un_pedido_sin_pagar_si_se_puede_cancelar(client, demo, headers, estados):
    """La regla mira la categoria 'completed', no is_final: cancelar un
    pedido impago tiene que seguir siendo posible."""
    pedido = _pedido(client, headers, demo)
    assert _avanzar(client, headers, pedido["id"], estados["cancelled"].id).status_code == 200


def test_el_pago_dividido_salda_y_desbloquea(client, demo, headers, estados):
    pedido = _pedido(client, headers, demo, cantidad=2)  # 36000
    for code in ("paid", "preparing", "ready"):
        _avanzar(client, headers, pedido["id"], estados[code].id)

    client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "cash", "amount": 20000},
    )
    saldo = client.get(f"/api/v1/orders/{pedido['id']}/balance", headers=headers).json()
    assert Decimal(saldo["pending"]) == Decimal("16000.00")
    assert saldo["is_settled"] is False
    assert _avanzar(client, headers, pedido["id"], estados["delivered"].id).status_code == 422

    client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "card", "amount": 16000},
    )
    saldo = client.get(f"/api/v1/orders/{pedido['id']}/balance", headers=headers).json()
    assert saldo["is_settled"] is True
    assert _avanzar(client, headers, pedido["id"], estados["delivered"].id).status_code == 200


def test_metodo_de_pago_no_soportado(client, demo, headers):
    pedido = _pedido(client, headers, demo)
    response = client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "trueque", "amount": 18000},
    )
    assert response.status_code == 422
    assert "no soportado" in response.json()["detail"]


def test_el_pago_es_idempotente(client, demo, headers):
    pedido = _pedido(client, headers, demo)
    payload = {"method": "cash", "amount": 18000, "idempotency_key": "vuelto-1"}

    primero = client.post(f"/api/v1/orders/{pedido['id']}/payments", headers=headers, json=payload)
    segundo = client.post(f"/api/v1/orders/{pedido['id']}/payments", headers=headers, json=payload)
    assert primero.json()["id"] == segundo.json()["id"]

    saldo = client.get(f"/api/v1/orders/{pedido['id']}/balance", headers=headers).json()
    assert Decimal(saldo["paid"]) == Decimal("18000.00")  # no se conto dos veces


def test_el_permiso_lo_declara_la_transicion_no_el_endpoint(
    client, db, demo, make_tenant, auth, estados
):
    """Principio 7: un cocinero avanza cocina pero no cobra, y eso sale de
    order_status_transitions.required_permission, no de un if en el codigo."""
    admin_headers = auth(demo["email"], demo["password"])
    pedido = _pedido(client, admin_headers, demo)

    # Un usuario del mismo tenant con permisos de cocina solamente
    from app.repositories import role_repository, user_repository
    from app.core.security import hash_password

    rol = role_repository.create(db, tenant_id=demo["tenant"].id, code="cocina", name="Cocina")
    rol.permissions = role_repository.permissions_by_codes(
        db, ["orders.view", "orders.advance_kitchen"]
    )
    user_repository.create(
        db,
        tenant_id=demo["tenant"].id,
        role_id=rol.id,
        branch_id=demo["branch"].id,
        name="Cocinero",
        email="cocina@test.local",
        password_hash=hash_password("clave-de-prueba"),
    )
    db.commit()
    cocina_headers = auth("cocina@test.local", "clave-de-prueba")

    # pending -> paid exige payments.register: no lo tiene
    negado = _avanzar(client, cocina_headers, pedido["id"], estados["paid"].id)
    assert negado.status_code == 422
    assert "payments.register" in negado.json()["detail"]

    # El admin lo pasa a paid y ahi cocina si puede avanzar
    _avanzar(client, admin_headers, pedido["id"], estados["paid"].id)
    permitido = _avanzar(client, cocina_headers, pedido["id"], estados["preparing"].id)
    assert permitido.status_code == 200


def test_el_kds_filtra_por_categoria(client, demo, headers, estados):
    pedido = _pedido(client, headers, demo)

    tablero = client.get("/api/v1/kitchen/orders", headers=headers).json()
    assert [o["order_number"] for o in tablero] == [pedido["order_number"]]
    assert tablero[0]["status"]["category"] == "new"
    # Trae las transiciones permitidas para que el KDS arme sus botones
    assert sorted(s["code"] for s in tablero[0]["next_statuses"]) == ["cancelled", "paid"]

    # Al completarse sale del tablero: 'completed' no es categoria de cocina
    for code in ("paid", "preparing", "ready"):
        _avanzar(client, headers, pedido["id"], estados[code].id)
    client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "cash", "amount": 18000},
    )
    _avanzar(client, headers, pedido["id"], estados["delivered"].id)

    assert client.get("/api/v1/kitchen/orders", headers=headers).json() == []
