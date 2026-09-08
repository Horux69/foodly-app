"""Toma de pedidos: validaciones, congelado de precios y totales."""

from decimal import Decimal

import pytest


def _pedir(client, headers, **overrides):
    payload = {"channel": "counter", "items": []}
    payload.update(overrides)
    return client.post("/api/v1/orders", headers=headers, json=payload)


@pytest.fixture
def headers(client, demo, auth):
    return auth(demo["email"], demo["password"])


def test_crea_pedido_con_totales_del_dominio(client, demo, headers):
    response = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 2}]
    )
    assert response.status_code == 201

    body = response.json()
    assert body["order_number"] == "TST-00001"  # prefijo de la sucursal
    assert Decimal(body["subtotal"]) == Decimal("36000.00")
    # 8% incluido en el precio: 36000 - 36000/1.08
    assert Decimal(body["tax_total"]) == Decimal("2666.67")
    assert Decimal(body["total"]) == Decimal("36000.00")


def test_la_numeracion_avanza_por_sucursal(client, demo, headers):
    numeros = [
        _pedir(client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}])
        .json()["order_number"]
        for _ in range(3)
    ]
    assert numeros == ["TST-00001", "TST-00002", "TST-00003"]


def test_preview_calcula_sin_crear_el_pedido(client, demo, headers):
    payload = {"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 2}]}
    preview = client.post("/api/v1/orders/preview", headers=headers, json=payload)
    assert preview.status_code == 200
    assert Decimal(preview.json()["total"]) == Decimal("36000.00")

    # No debe haber quedado nada creado
    assert client.get("/api/v1/orders", headers=headers).json() == []


def test_pedido_sin_productos_se_rechaza(client, demo, headers):
    assert _pedir(client, headers, items=[]).status_code == 422


def test_cantidad_cero_se_rechaza(client, demo, headers):
    response = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 0}]
    )
    assert response.status_code == 422


def test_producto_agotado_no_se_puede_pedir(client, demo, headers):
    client.patch(
        f"/api/v1/menu/items/{demo['item'].id}/availability",
        headers=headers,
        json={"is_available": False},
    )
    response = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    )
    assert response.status_code == 422
    assert "no está disponible" in response.json()["detail"]


def test_producto_archivado_no_se_puede_pedir(client, demo, headers):
    client.patch(
        f"/api/v1/menu/items/{demo['item'].id}", headers=headers, json={"is_archived": True}
    )
    response = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    )
    assert response.status_code == 422


def test_canal_no_habilitado_se_rechaza(client, demo, headers):
    """fast_food trae counter y delivery; whatsapp no."""
    response = _pedir(
        client,
        headers,
        channel="whatsapp",
        items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}],
    )
    assert response.status_code == 422
    assert "no esta habilitado" in response.json()["detail"]


def test_habilitar_el_canal_por_configuracion_lo_permite(client, demo, headers):
    """El mismo pedido pasa de rechazado a aceptado sin tocar codigo."""
    item = {"menu_item_id": str(demo["item"].id), "quantity": 1}
    assert _pedir(client, headers, channel="whatsapp", items=[item]).status_code == 422

    client.patch(
        "/api/v1/settings", headers=headers, json={"channels": ["counter", "delivery", "whatsapp"]}
    )
    assert _pedir(client, headers, channel="whatsapp", items=[item]).status_code == 201


def test_mesa_en_restaurante_sin_mesas_se_rechaza(client, demo, headers):
    response = _pedir(
        client,
        headers,
        table_code="M1",
        items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}],
    )
    assert response.status_code == 422
    assert "no maneja mesas" in response.json()["detail"]


def test_propina_donde_no_se_pide_se_rechaza(client, demo, headers):
    response = _pedir(
        client, headers, tip=5000, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    )
    assert response.status_code == 422
    assert "propina" in response.json()["detail"]


def test_idempotencia_no_duplica_el_pedido(client, demo, headers):
    payload = {
        "channel": "counter",
        "items": [{"menu_item_id": str(demo["item"].id), "quantity": 1}],
        "idempotency_key": "ticket-123",
    }
    primero = client.post("/api/v1/orders", headers=headers, json=payload).json()
    segundo = client.post("/api/v1/orders", headers=headers, json=payload).json()

    assert primero["id"] == segundo["id"]
    assert len(client.get("/api/v1/orders", headers=headers).json()) == 1


def test_el_precio_queda_congelado_en_el_pedido(client, db, demo, headers):
    """Principio 8: cambiar el menu no reescribe la historia."""
    pedido = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    ).json()
    assert Decimal(pedido["items"][0]["unit_price"]) == Decimal("18000.00")

    client.patch(
        f"/api/v1/menu/items/{demo['item'].id}", headers=headers, json={"base_price": 25000}
    )

    vuelto_a_leer = client.get(f"/api/v1/orders/{pedido['id']}", headers=headers).json()
    assert Decimal(vuelto_a_leer["items"][0]["unit_price"]) == Decimal("18000.00")
    assert vuelto_a_leer["items"][0]["name_snapshot"] == "Clasica"

    # Y el pedido nuevo si toma el precio nuevo
    nuevo = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    ).json()
    assert Decimal(nuevo["items"][0]["unit_price"]) == Decimal("25000.00")


def test_el_override_de_sucursal_manda_sobre_el_precio_base(client, demo, headers):
    client.put(
        f"/api/v1/menu/items/{demo['item'].id}/branch-override",
        headers=headers,
        json={"branch_id": str(demo["branch"].id), "price": 15000},
    )
    pedido = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    ).json()
    assert Decimal(pedido["items"][0]["unit_price"]) == Decimal("15000.00")


def test_modificador_obligatorio_y_maximo(client, db, demo, headers):
    from app.models.modifier import Modifier, ModifierGroup, item_modifier_groups

    grupo = ModifierGroup(
        tenant_id=demo["tenant"].id, name="Tamano", min_select=1, max_select=1, is_required=True
    )
    db.add(grupo)
    db.flush()
    grande = Modifier(group_id=grupo.id, name="Grande", price_delta=Decimal("3000"))
    normal = Modifier(group_id=grupo.id, name="Normal", price_delta=Decimal("0"))
    db.add_all([grande, normal])
    db.flush()
    db.execute(item_modifier_groups.insert().values(item_id=demo["item"].id, group_id=grupo.id))
    db.commit()

    base = {"menu_item_id": str(demo["item"].id), "quantity": 1}

    sin_elegir = _pedir(client, headers, items=[{**base, "modifier_ids": []}])
    assert sin_elegir.status_code == 422
    assert "obligatorio" in sin_elegir.json()["detail"]

    dos = _pedir(
        client, headers, items=[{**base, "modifier_ids": [str(grande.id), str(normal.id)]}]
    )
    assert dos.status_code == 422
    assert "máximo" in dos.json()["detail"]

    correcto = _pedir(client, headers, items=[{**base, "modifier_ids": [str(grande.id)]}])
    assert correcto.status_code == 201
    # El modificador suma al total y queda con su nombre congelado
    assert Decimal(correcto.json()["total"]) == Decimal("21000.00")
    assert correcto.json()["items"][0]["modifiers"][0]["name_snapshot"] == "Grande"


def test_un_modificador_de_otro_producto_se_rechaza(client, db, demo, headers):
    from app.models.modifier import Modifier, ModifierGroup

    grupo = ModifierGroup(tenant_id=demo["tenant"].id, name="Suelto", min_select=0, max_select=1)
    db.add(grupo)
    db.flush()
    suelto = Modifier(group_id=grupo.id, name="Suelto", price_delta=Decimal("1000"))
    db.add(suelto)
    db.commit()

    response = _pedir(
        client,
        headers,
        items=[
            {"menu_item_id": str(demo["item"].id), "quantity": 1, "modifier_ids": [str(suelto.id)]}
        ],
    )
    assert response.status_code == 422
    assert "no tiene los modificadores" in response.json()["detail"]


def test_la_sucursal_cerrada_rechaza_el_pedido(client, db, demo, headers):
    """Se configura un horario imposible para el dia de hoy."""
    from datetime import datetime, time
    from zoneinfo import ZoneInfo

    from app.models.branch import BranchSchedule

    hoy = datetime.now(ZoneInfo(demo["branch"].timezone)).weekday()
    db.add(
        BranchSchedule(
            branch_id=demo["branch"].id,
            weekday=hoy,
            opens_at=time(3, 0),
            closes_at=time(3, 1),
            channel=None,
        )
    )
    db.commit()

    response = _pedir(
        client, headers, items=[{"menu_item_id": str(demo["item"].id), "quantity": 1}]
    )
    assert response.status_code == 422
    assert "cerrada" in response.json()["detail"]
