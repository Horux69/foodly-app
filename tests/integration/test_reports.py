"""Reportes.

Estas consultas son SQL crudo con parametros: aqui es donde se atrapa un
cast mal escrito o una agregacion que cuenta lo que no debe.
"""

from decimal import Decimal

import pytest

from app.repositories import order_status_repository


@pytest.fixture
def headers(client, demo, auth):
    return auth(demo["email"], demo["password"])


@pytest.fixture
def estados(db, demo):
    return {s.code: s for s in order_status_repository.list_statuses(db, demo["tenant"].id)}


def _vender(client, headers, demo, estados, cantidad=1, channel="counter"):
    """Pedido llevado hasta 'completed' y pagado, que es lo que cuenta como venta."""
    pedido = client.post(
        "/api/v1/orders",
        headers=headers,
        json={
            "channel": channel,
            "items": [{"menu_item_id": str(demo["item"].id), "quantity": cantidad}],
        },
    ).json()

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
    return pedido


def test_sin_ventas_los_totales_son_cero(client, headers):
    totales = client.get("/api/v1/reports/sales", headers=headers).json()["totals"]
    assert totales["orders"] == 0
    assert Decimal(totales["revenue"]) == Decimal("0")


def test_las_ventas_suman_y_los_desgloses_cuadran(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=2)  # 36000 counter
    _vender(client, headers, demo, estados, cantidad=1, channel="delivery")  # 18000

    ventas = client.get("/api/v1/reports/sales", headers=headers).json()
    assert ventas["totals"]["orders"] == 2
    assert Decimal(ventas["totals"]["revenue"]) == Decimal("54000.00")
    assert Decimal(ventas["totals"]["avg_ticket"]) == Decimal("27000.00")

    # Cada desglose tiene que sumar el mismo total
    for clave in ("by_day", "by_channel", "by_branch"):
        assert sum(Decimal(r["revenue"]) for r in ventas[clave]) == Decimal("54000.00")

    canales = {r["channel"]: Decimal(r["revenue"]) for r in ventas["by_channel"]}
    assert canales == {"counter": Decimal("36000.00"), "delivery": Decimal("18000.00")}


def test_los_cancelados_no_son_venta(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=1)

    cancelado = client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 10}]},
    ).json()
    client.post(
        f"/api/v1/orders/{cancelado['id']}/status",
        headers=headers,
        json={"to_status_id": str(estados["cancelled"].id)},
    )

    totales = client.get("/api/v1/reports/sales", headers=headers).json()["totals"]
    assert totales["orders"] == 1
    assert Decimal(totales["revenue"]) == Decimal("18000.00")


def test_un_pedido_abierto_todavia_no_es_venta(client, demo, headers):
    client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 1}]},
    )
    totales = client.get("/api/v1/reports/sales", headers=headers).json()["totals"]
    assert totales["orders"] == 0


def test_productos_mas_vendidos(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=2)
    _vender(client, headers, demo, estados, cantidad=3)

    productos = client.get("/api/v1/reports/top-products", headers=headers).json()
    assert len(productos) == 1
    assert productos[0]["name"] == "Clasica"
    assert productos[0]["units"] == 5
    assert Decimal(productos[0]["revenue"]) == Decimal("90000.00")


def test_los_productos_se_agrupan_por_id_aunque_se_renombren(client, demo, headers, estados):
    """Se agrupa por menu_item_id y no por name_snapshot: renombrar un
    producto a mitad del periodo no debe partirlo en dos filas."""
    _vender(client, headers, demo, estados, cantidad=1)
    client.patch(
        f"/api/v1/menu/items/{demo['item'].id}", headers=headers, json={"name": "Clasica XL"}
    )
    _vender(client, headers, demo, estados, cantidad=1)

    productos = client.get("/api/v1/reports/top-products", headers=headers).json()
    assert len(productos) == 1
    assert productos[0]["units"] == 2


def test_horas_pico_mide_demanda_e_ignora_cancelados(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=1)
    # Un pedido abierto tambien es demanda
    client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 1}]},
    )
    cancelado = client.post(
        "/api/v1/orders",
        headers=headers,
        json={"channel": "counter", "items": [{"menu_item_id": str(demo["item"].id), "quantity": 1}]},
    ).json()
    client.post(
        f"/api/v1/orders/{cancelado['id']}/status",
        headers=headers,
        json={"to_status_id": str(estados["cancelled"].id)},
    )

    horas = client.get("/api/v1/reports/peak-hours", headers=headers).json()
    assert sum(h["orders"] for h in horas) == 2


def test_tiempos_de_cocina_salen_del_historial(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=1)

    tiempos = client.get("/api/v1/reports/prep-times", headers=headers).json()
    assert tiempos["orders"] == 1
    # El test corre en milisegundos; lo que importa es que exista la medicion
    assert tiempos["median_minutes"] is not None
    assert tiempos["avg_minutes"] >= 0


def test_sin_pedidos_medidos_los_tiempos_vienen_vacios(client, headers):
    tiempos = client.get("/api/v1/reports/prep-times", headers=headers).json()
    assert tiempos["orders"] == 0
    assert tiempos["median_minutes"] is None


def test_el_filtro_por_sucursal_funciona(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=1)

    propia = client.get(
        f"/api/v1/reports/sales?branch_id={demo['branch'].id}", headers=headers
    ).json()
    assert propia["totals"]["orders"] == 1


def test_un_rango_invertido_se_rechaza(client, headers):
    response = client.get(
        "/api/v1/reports/sales?from_date=2026-09-10&to_date=2026-09-01", headers=headers
    )
    assert response.status_code == 422


def test_un_periodo_sin_ventas_devuelve_vacio(client, demo, headers, estados):
    _vender(client, headers, demo, estados, cantidad=1)

    ventas = client.get(
        "/api/v1/reports/sales?from_date=2020-01-01&to_date=2020-01-31", headers=headers
    ).json()
    assert ventas["totals"]["orders"] == 0
    assert ventas["by_day"] == []
