"""Determina si una sucursal esta abierta para un canal en un momento dado.

Pura y testeable: recibe el dia/hora ya resueltos (en la zona horaria de la
sucursal) y la lista de horarios configurados. No conoce zonas horarias ni
"ahora": eso lo resuelve quien la llama.
"""

from dataclasses import dataclass
from datetime import time


@dataclass(frozen=True)
class ScheduleWindow:
    weekday: int
    opens_at: time
    closes_at: time
    channel: str | None
    is_active: bool


def is_branch_open(*, weekday: int, at: time, channel: str, schedules: list[ScheduleWindow]) -> bool:
    for window in schedules:
        if not window.is_active or window.weekday != weekday:
            continue
        if window.channel is not None and window.channel != channel:
            continue
        if window.opens_at <= at <= window.closes_at:
            return True
    return False
