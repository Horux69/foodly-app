"""Domicilios: zonas, tarifa, mínimo por zona y seguimiento de la entrega."""

from decimal import Decimal

import pytest

from app.repositories import order_status_repository


@pytest.fixture
def headers(client, demo, auth):
    return auth(demo["email"], demo["password"])


@pytest.fixture
def zona(client, demo, headers):
    """Zona con tarifa de 5000 y mínimo de 30000."""
    response = client.post(
        f"/api/v1/branches/{demo['branch'].id}/delivery-zones",
        headers=headers,
        json={"name": "Norte", "fee": 5000, "min_order": 30000, "est_minutes": 30},
    )
    assert response.status_code == 201, response.text
    return response.json()


def _pedir(client, headers, demo, cantidad, **extra):
    payload = {
        "channel": "delivery",
        "items": [{"menu_item_id": str(demo["item"].id), "quantity": cantidad}],
    }
    payload.update(extra)
    return client.post("/api/v1/orders", headers=headers, json=payload)


def test_crear_y_listar_zonas(client, demo, headers, zona):
    assert Decimal(zona["fee"]) == Decimal("5000.00")

    zonas = client.get(f"/api/v1/branches/{demo['branch'].id}/delivery-zones", headers=headers).json()
    assert [z["name"] for z in zonas] == ["Norte"]


def test_nombre_de_zona_duplicado(client, demo, headers, zona):
    response = client.post(
        f"/api/v1/branches/{demo['branch'].id}/delivery-zones",
        headers=headers,
        json={"name": "Norte", "fee": 1000},
    )
    assert response.status_code == 422
    assert "Ya existe" in response.json()["detail"]


def test_la_tarifa_la_pone_la_zona_y_no_quien_pide(client, demo, headers, zona):
    """El cliente manda 99 de domicilio; manda la zona."""
    response = _pedir(
        client,
        headers,
        demo,
        2,  # 36000, alcanza el mínimo
        delivery={"address": "Calle 100 #20-30", "zone_id": zona["id"]},
        delivery_fee=99,
    )
    assert response.status_code == 201, response.text

    pedido = response.json()
    assert Decimal(pedido["delivery_fee"]) == Decimal("5000.00")
    assert Decimal(pedido["total"]) == Decimal("41000.00")  # 36000 + 5000


def test_no_alcanza_el_minimo_de_la_zona(client, demo, headers, zona):
    response = _pedir(
        client, headers, demo, 1, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    )
    assert response.status_code == 422
    assert "mínimo" in response.json()["detail"]
    assert "faltan 12000" in response.json()["detail"]


def test_el_domicilio_no_cuenta_para_alcanzar_el_minimo(client, demo, headers, zona):
    """18000 de comida + 5000 de envío suman 23000, pero el mínimo mira solo
    la comida, así que sigue faltando."""
    response = _pedir(
        client, headers, demo, 1, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    )
    assert response.status_code == 422


def test_un_domicilio_sin_zona_se_acepta(client, demo, headers):
    """Un restaurante puede repartir sin haber configurado zonas."""
    response = _pedir(client, headers, demo, 1, delivery={"address": "Calle 100"}, delivery_fee=3000)
    assert response.status_code == 201
    assert Decimal(response.json()["delivery_fee"]) == Decimal("3000.00")


def test_una_zona_inactiva_no_se_puede_usar(client, demo, headers, zona):
    client.patch(f"/api/v1/delivery-zones/{zona['id']}/active", headers=headers, json={"is_active": False})

    response = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    )
    assert response.status_code == 422
    assert "no está activa" in response.json()["detail"]


def test_los_datos_de_entrega_quedan_en_el_pedido(client, demo, headers, zona):
    pedido = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100 #20-30", "zone_id": zona["id"]}
    ).json()

    entrega = client.get(f"/api/v1/orders/{pedido['id']}/delivery", headers=headers).json()
    assert entrega["address"] == "Calle 100 #20-30"
    assert entrega["zone_name"] == "Norte"
    assert entrega["courier_name"] is None
    assert entrega["dispatched_at"] is None


def test_un_pedido_que_no_es_domicilio_no_tiene_entrega(client, demo, headers):
    pedido = client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 1}]},
    ).json()

    response = client.get(f"/api/v1/orders/{pedido['id']}/delivery", headers=headers)
    assert response.status_code == 404
    assert "no es un domicilio" in response.json()["detail"]


def test_asignar_repartidor(client, db, demo, headers, zona, auth):
    from app.core.security import hash_password
    from app.repositories import role_repository, user_repository

    rol = role_repository.create(db, tenant_id=demo["tenant"].id, code="moto", name="Repartidor")
    rol.permissions = role_repository.permissions_by_codes(db, ["orders.view", "delivery.complete"])
    repartidor = user_repository.create(
        db,
        tenant_id=demo["tenant"].id,
        role_id=rol.id,
        branch_id=demo["branch"].id,
        name="Luis Moto",
        email="moto@test.local",
        password_hash=hash_password("clave-de-prueba"),
    )
    db.commit()

    pedido = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    ).json()

    response = client.put(
        f"/api/v1/orders/{pedido['id']}/delivery/courier",
        headers=headers,
        json={"courier_id": str(repartidor.id)},
    )
    assert response.status_code == 200
    assert response.json()["courier_name"] == "Luis Moto"


def test_no_se_puede_asignar_un_repartidor_de_otra_empresa(client, demo, headers, zona, make_tenant):
    otra = make_tenant(name="Otra", branch_code="OTR", email="otra@test.local")
    pedido = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    ).json()

    response = client.put(
        f"/api/v1/orders/{pedido['id']}/delivery/courier",
        headers=headers,
        json={"courier_id": str(otra["user"].id)},
    )
    assert response.status_code == 422
    assert "no existe" in response.json()["detail"]


def test_las_marcas_de_tiempo_siguen_la_categoria_del_estado(client, db, demo, headers, zona):
    """El restaurante demo es fast_food y no tiene estado 'in_transit', pero
    sí 'completed': al entregarse debe quedar la hora, sin que nadie compare
    códigos de estado."""
    pedido = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100", "zone_id": zona["id"]}
    ).json()

    estados = {s.code: s for s in order_status_repository.list_statuses(db, demo["tenant"].id)}
    for code in ("paid", "preparing", "ready"):
        client.post(
            f"/api/v1/orders/{pedido['id']}/status",
            headers=headers,
            json={"to_status_id": str(estados[code].id)},
        )
    client.post(
        f"/api/v1/orders/{pedido['id']}/payments",
        headers=headers,
        json={"method": "cash", "amount": pedido["total"]},
    )
    client.post(
        f"/api/v1/orders/{pedido['id']}/status",
        headers=headers,
        json={"to_status_id": str(estados["delivered"].id)},
    )

    entrega = client.get(f"/api/v1/orders/{pedido['id']}/delivery", headers=headers).json()
    assert entrega["delivered_at"] is not None


def test_la_zona_de_otra_empresa_no_se_puede_usar(client, demo, headers, make_tenant, auth):
    ajena = make_tenant(name="Ajena", branch_code="AJE", email="ajena@test.local")
    zona_ajena = client.post(
        f"/api/v1/branches/{ajena['branch'].id}/delivery-zones",
        headers=auth(ajena["email"], ajena["password"]),
        json={"name": "Sur", "fee": 1000},
    ).json()

    response = _pedir(
        client, headers, demo, 2, delivery={"address": "Calle 100", "zone_id": zona_ajena["id"]}
    )
    assert response.status_code == 422
    assert "zona de domicilio no existe" in response.json()["detail"]
