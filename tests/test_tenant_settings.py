import pytest

from app.domain.tenant_settings import SettingsError, defaults_for, parse, validate


def test_defaults_por_modelo_de_negocio():
    assert parse(None, business_type="fast_food").uses_tables is False
    assert parse(None, business_type="table_service").uses_tables is True
    assert "delivery" in parse(None, business_type="delivery").channels


def test_business_type_desconocido_cae_en_fast_food():
    # Un tenant con un modelo que aun no tiene preset debe poder operar.
    assert parse(None, business_type="food_truck").channels == tuple(defaults_for("fast_food")["channels"])


def test_configuracion_parcial_completa_con_defaults():
    settings = parse({"asks_tip": True}, business_type="fast_food")
    assert settings.asks_tip is True
    assert settings.channels == ("counter", "delivery")


def test_allows_channel():
    settings = parse({"channels": ["counter"]}, business_type="fast_food")
    assert settings.allows_channel("counter")
    assert not settings.allows_channel("delivery")


def test_valida_canal_desconocido():
    with pytest.raises(SettingsError):
        validate({"channels": ["telepatia"]})


def test_valida_lista_de_canales_vacia():
    with pytest.raises(SettingsError):
        validate({"channels": []})


def test_valida_tipo_de_flag():
    with pytest.raises(SettingsError):
        validate({"asks_tip": "si"})


def test_canal_mesa_exige_uses_tables():
    with pytest.raises(SettingsError):
        validate({"channels": ["table"], "uses_tables": False})
    validate({"channels": ["table"], "uses_tables": True})


def test_configuracion_valida_no_levanta():
    validate({"channels": ["counter", "whatsapp"], "uses_tables": False, "asks_tip": True})
