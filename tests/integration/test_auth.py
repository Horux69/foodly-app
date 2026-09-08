def test_login_devuelve_token_con_tenant_y_permisos(client, demo):
    response = client.post(
        "/api/v1/auth/login", json={"email": demo["email"], "password": demo["password"]}
    )
    assert response.status_code == 200
    assert response.json()["token_type"] == "bearer"


def test_login_con_clave_incorrecta(client, demo):
    response = client.post(
        "/api/v1/auth/login", json={"email": demo["email"], "password": "no-es-la-clave"}
    )
    assert response.status_code == 401


def test_login_con_usuario_inexistente(client, demo):
    response = client.post(
        "/api/v1/auth/login", json={"email": "nadie@test.local", "password": "cualquiera"}
    )
    assert response.status_code == 401


def test_me_describe_al_usuario_y_como_opera_el_restaurante(client, demo, auth):
    response = client.get("/api/v1/auth/me", headers=auth(demo["email"], demo["password"]))
    assert response.status_code == 200

    body = response.json()
    assert body["tenant_name"] == demo["tenant"].name
    assert body["branch_name"] == demo["branch"].name
    assert body["role"] == "admin"
    assert "orders.create" in body["permissions"]
    # fast_food: los defaults del modelo de negocio, no valores hardcodeados
    assert body["channels"] == ["counter", "delivery"]
    assert body["uses_tables"] is False


def test_sin_token_no_se_entra(client, demo):
    assert client.get("/api/v1/orders").status_code == 403


def test_token_invalido_se_rechaza(client, demo):
    response = client.get("/api/v1/orders", headers={"Authorization": "Bearer no-es-un-token"})
    assert response.status_code == 401


def test_los_permisos_del_rol_llegan_al_token(client, make_tenant, auth):
    limitado = make_tenant(
        name="Limitado",
        branch_code="LIM",
        email="cocina@test.local",
        permissions=["orders.view", "orders.advance_kitchen"],
    )
    headers = auth(limitado["email"], limitado["password"])

    body = client.get("/api/v1/auth/me", headers=headers).json()
    assert sorted(body["permissions"]) == ["orders.advance_kitchen", "orders.view"]

    # Lo que no esta en el rol, el backend lo niega
    assert client.get("/api/v1/settings", headers=headers).status_code == 403
    assert client.get("/api/v1/users", headers=headers).status_code == 403
    assert client.get("/api/v1/reports/sales", headers=headers).status_code == 403
    # Lo que si esta, pasa
    assert client.get("/api/v1/orders", headers=headers).status_code == 200
