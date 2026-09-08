"""Administracion: aprovisionamiento por modelo de negocio, configuracion,
impuestos y las salvaguardas de roles."""

from decimal import Decimal

import pytest

from app.repositories import order_status_repository


@pytest.fixture
def headers(client, demo, auth):
    return auth(demo["email"], demo["password"])


def test_el_aprovisionamiento_siembra_segun_el_modelo_de_negocio(db, make_tenant):
    """Dos restaurantes distintos, cero codigo distinto."""
    rapida = make_tenant(name="Rapida", branch_code="RAP", email="rap@test.local")
    mesa = make_tenant(
        name="Mesa", business_type="table_service", branch_code="MES", email="mes@test.local"
    )

    codigos = lambda t: [  # noqa: E731
        s.code for s in order_status_repository.list_statuses(db, t["tenant"].id)
    ]
    assert codigos(rapida) == ["pending", "paid", "preparing", "ready", "delivered", "cancelled"]
    # Servicio en mesa no tiene prepago: se abre la cuenta y se paga al final
    assert codigos(mesa) == ["open", "preparing", "ready", "served", "cancelled"]

    # Y cada uno arranca con su configuracion, no con una generica
    assert mesa["tenant"].settings["uses_tables"] is True
    assert rapida["tenant"].settings["uses_tables"] is False


def test_el_rol_admin_nace_con_todos_los_permisos(client, headers):
    roles = client.get("/api/v1/roles", headers=headers).json()
    admin = next(r for r in roles if r["code"] == "admin")
    permisos = client.get("/api/v1/permissions", headers=headers).json()

    assert admin["is_system"] is True
    assert len(admin["permissions"]) == len(permisos)


def test_los_roles_de_sistema_no_se_pueden_desarmar(client, headers):
    """Quitarle users.manage al rol admin dejaria al tenant sin nadie que
    pueda devolverselo."""
    roles = client.get("/api/v1/roles", headers=headers).json()
    admin = next(r for r in roles if r["code"] == "admin")

    response = client.put(
        f"/api/v1/roles/{admin['id']}/permissions",
        headers=headers,
        json={"permissions": ["menu.view"]},
    )
    assert response.status_code == 422
    assert "sistema" in response.json()["detail"]


def test_un_permiso_inexistente_no_se_puede_asignar(client, headers):
    response = client.post(
        "/api/v1/roles",
        headers=headers,
        json={"code": "raro", "name": "Raro", "permissions": ["orders.teletransportar"]},
    )
    assert response.status_code == 422
    assert "desconocidos" in response.json()["detail"]


def test_alta_de_usuario_y_login_con_sus_permisos(client, headers, demo, auth):
    nuevo_rol = client.post(
        "/api/v1/roles",
        headers=headers,
        json={"code": "mesero", "name": "Mesero", "permissions": ["orders.create", "orders.view"]},
    ).json()

    creado = client.post(
        "/api/v1/users",
        headers=headers,
        json={
            "name": "Ana",
            "email": "ana@test.local",
            "password": "clave-larga-2026",
            "role_id": nuevo_rol["id"],
            "branch_id": str(demo["branch"].id),
        },
    )
    assert creado.status_code == 201
    assert "password" not in creado.json()  # nunca devolver el hash

    # La clave quedo hasheada bien: el usuario entra
    me = client.get("/api/v1/auth/me", headers=auth("ana@test.local", "clave-larga-2026")).json()
    assert me["role"] == "mesero"
    assert sorted(me["permissions"]) == ["orders.create", "orders.view"]


def test_email_duplicado_en_el_mismo_tenant(client, headers, demo):
    roles = client.get("/api/v1/roles", headers=headers).json()
    response = client.post(
        "/api/v1/users",
        headers=headers,
        json={
            "name": "Otro",
            "email": demo["email"],
            "password": "clave-larga-2026",
            "role_id": roles[0]["id"],
        },
    )
    assert response.status_code == 422
    assert "ya existe" in response.json()["detail"].lower()


def test_configuracion_valida_la_coherencia(client, headers):
    incoherente = client.patch(
        "/api/v1/settings", headers=headers, json={"channels": ["counter", "table"]}
    )
    assert incoherente.status_code == 422
    assert "uses_tables" in incoherente.json()["detail"]

    desconocido = client.patch(
        "/api/v1/settings", headers=headers, json={"channels": ["paloma-mensajera"]}
    )
    assert desconocido.status_code == 422


def test_un_solo_impuesto_por_defecto(client, headers):
    creado = client.post(
        "/api/v1/tax-rates",
        headers=headers,
        json={"name": "IVA 19%", "rate": 0.19, "is_default": True},
    )
    assert creado.status_code == 201

    impuestos = client.get("/api/v1/tax-rates", headers=headers).json()
    defaults = [t for t in impuestos if t["is_default"]]
    assert len(defaults) == 1
    assert defaults[0]["name"] == "IVA 19%"


def test_la_tasa_se_acota_para_atajar_el_error_de_digitacion(client, headers):
    """Escribir 8 en vez de 0.08 seria cobrar 800%."""
    response = client.post(
        "/api/v1/tax-rates", headers=headers, json={"name": "Mal", "rate": 8}
    )
    assert response.status_code == 422


def test_un_producto_nuevo_hereda_el_impuesto_por_defecto(client, headers, demo):
    client.post(
        "/api/v1/tax-rates",
        headers=headers,
        json={"name": "Impoconsumo", "rate": 0.08, "is_default": True},
    )
    creado = client.post(
        "/api/v1/menu/items",
        headers=headers,
        json={
            "category_id": str(demo["category"].id),
            "name": "Papas",
            "base_price": 9000,
        },
    ).json()
    assert creado["tax_rate_id"] is not None


def test_el_catalogo_muestra_lo_que_el_menu_operativo_esconde(client, headers, demo):
    client.patch(
        f"/api/v1/menu/items/{demo['item'].id}", headers=headers, json={"is_archived": True}
    )

    operativo = client.get("/api/v1/menu", headers=headers).json()
    assert all(not cat["items"] for cat in operativo)

    catalogo = client.get("/api/v1/menu/catalog", headers=headers).json()
    archivados = [i for i in catalogo["items"] if i["is_archived"]]
    assert len(archivados) == 1
    assert Decimal(archivados[0]["base_price"]) == Decimal("18000.00")


def test_codigo_de_sucursal_duplicado(client, headers):
    primero = client.post(
        "/api/v1/branches", headers=headers, json={"name": "Norte", "code": "NOR"}
    )
    assert primero.status_code == 201

    segundo = client.post(
        "/api/v1/branches", headers=headers, json={"name": "Otra", "code": "NOR"}
    )
    assert segundo.status_code == 422


def test_no_se_crean_mesas_donde_no_se_manejan_mesas(client, headers, demo):
    response = client.post(
        f"/api/v1/branches/{demo['branch'].id}/tables",
        headers=headers,
        json={"code": "M1", "capacity": 4},
    )
    assert response.status_code == 422
    assert "no maneja mesas" in response.json()["detail"]

    client.patch("/api/v1/settings", headers=headers, json={"uses_tables": True})
    assert (
        client.post(
            f"/api/v1/branches/{demo['branch'].id}/tables",
            headers=headers,
            json={"code": "M1", "capacity": 4},
        ).status_code
        == 201
    )
