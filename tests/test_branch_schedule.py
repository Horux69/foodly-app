from datetime import time

from app.domain.branch_schedule import ScheduleWindow, is_branch_open

ALL_CHANNELS_1022 = ScheduleWindow(weekday=1, opens_at=time(10, 0), closes_at=time(22, 0), channel=None, is_active=True)
DELIVERY_ONLY_1800_2300 = ScheduleWindow(
    weekday=1, opens_at=time(18, 0), closes_at=time(23, 0), channel="delivery", is_active=True
)
INACTIVE = ScheduleWindow(weekday=1, opens_at=time(0, 0), closes_at=time(23, 59), channel=None, is_active=False)


def test_dentro_de_horario_general():
    assert is_branch_open(weekday=1, at=time(12, 0), channel="counter", schedules=[ALL_CHANNELS_1022])


def test_fuera_de_horario():
    assert not is_branch_open(weekday=1, at=time(23, 0), channel="counter", schedules=[ALL_CHANNELS_1022])


def test_dia_distinto_no_aplica():
    assert not is_branch_open(weekday=2, at=time(12, 0), channel="counter", schedules=[ALL_CHANNELS_1022])


def test_horario_especifico_de_canal_no_aplica_a_otro_canal():
    assert not is_branch_open(weekday=1, at=time(19, 0), channel="counter", schedules=[DELIVERY_ONLY_1800_2300])
    assert is_branch_open(weekday=1, at=time(19, 0), channel="delivery", schedules=[DELIVERY_ONLY_1800_2300])


def test_horario_inactivo_se_ignora():
    assert not is_branch_open(weekday=1, at=time(12, 0), channel="counter", schedules=[INACTIVE])


def test_varios_horarios_basta_con_que_uno_aplique():
    schedules = [DELIVERY_ONLY_1800_2300, ALL_CHANNELS_1022]
    assert is_branch_open(weekday=1, at=time(12, 0), channel="counter", schedules=schedules)
