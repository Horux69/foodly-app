import pytest

from app.domain.modifier_validation import ModifierGroupConstraint, ModifierValidationError, validate_selection

REQUIRED_ONE = ModifierGroupConstraint(group_id="1", name="Tamano", min_select=1, max_select=1, is_required=True)
OPTIONAL_UP_TO_TWO = ModifierGroupConstraint(
    group_id="2", name="Adiciones", min_select=0, max_select=2, is_required=False
)


def test_grupo_requerido_sin_seleccion_falla():
    with pytest.raises(ModifierValidationError):
        validate_selection(REQUIRED_ONE, 0)


def test_grupo_requerido_con_seleccion_exacta_pasa():
    validate_selection(REQUIRED_ONE, 1)


def test_grupo_opcional_sin_seleccion_pasa():
    validate_selection(OPTIONAL_UP_TO_TWO, 0)


def test_excede_el_maximo_falla():
    with pytest.raises(ModifierValidationError):
        validate_selection(OPTIONAL_UP_TO_TWO, 3)


def test_bajo_el_minimo_falla():
    below_min = ModifierGroupConstraint(group_id="3", name="Salsas", min_select=2, max_select=3, is_required=False)
    with pytest.raises(ModifierValidationError):
        validate_selection(below_min, 1)
