import pytest

from app.domain.status_machine import (
    Status, StatusMachine, Transition, TransitionError,
)

PEND = Status(id="1", code="pending", category="new", is_initial=True, is_final=False)
PREP = Status(id="2", code="preparing", category="kitchen", is_initial=False, is_final=False)
DONE = Status(id="3", code="delivered", category="completed", is_initial=False, is_final=True)


def machine():
    return StatusMachine(
        statuses=[PEND, PREP, DONE],
        transitions=[
            Transition("1", "2", "orders.advance_kitchen"),
            Transition("2", "3", None),
        ],
    )


def test_estado_inicial():
    assert machine().initial().code == "pending"


def test_transicion_valida_con_permiso():
    machine().validate("1", "2", ["orders.advance_kitchen"])


def test_transicion_sin_permiso_falla():
    with pytest.raises(TransitionError):
        machine().validate("1", "2", [])


def test_salto_de_estado_no_configurado_falla():
    with pytest.raises(TransitionError):
        machine().validate("1", "3", ["orders.advance_kitchen"])


def test_estado_final_no_avanza():
    with pytest.raises(TransitionError):
        machine().validate("3", "2", ["orders.advance_kitchen"])
