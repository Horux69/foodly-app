from decimal import Decimal

from app.domain.menu_pricing import resolve_effective_menu_item


def test_sin_override_usa_precio_y_disponibilidad_base():
    result = resolve_effective_menu_item(base_price=Decimal("18000"), base_is_available=True)
    assert result.price == Decimal("18000")
    assert result.is_available is True


def test_override_de_precio_manda_sobre_el_base():
    result = resolve_effective_menu_item(
        base_price=Decimal("18000"), base_is_available=True, override_price=Decimal("15000")
    )
    assert result.price == Decimal("15000")
    assert result.is_available is True


def test_override_de_disponibilidad_no_afecta_el_precio():
    result = resolve_effective_menu_item(
        base_price=Decimal("18000"), base_is_available=True, override_is_available=False
    )
    assert result.price == Decimal("18000")
    assert result.is_available is False


def test_override_en_false_explicito_se_respeta():
    # override_is_available=False es un valor valido, distinto de "sin override" (None)
    result = resolve_effective_menu_item(
        base_price=Decimal("18000"), base_is_available=False, override_is_available=False
    )
    assert result.is_available is False
