"""Motor de estados configurable por tenant.

Cada restaurante define sus propios estados y transiciones validas en
las tablas order_statuses / order_status_transitions. Este modulo solo
valida contra esa configuracion: no conoce ningun estado por nombre.
"""

from dataclasses import dataclass

# Categorias normalizadas. El nombre del estado varia por restaurante,
# la categoria no: el KDS y los reportes se apoyan en ella.
CATEGORIES = ("new", "kitchen", "ready", "in_transit", "completed", "cancelled")


@dataclass(frozen=True)
class Status:
    id: str
    code: str
    category: str
    is_initial: bool
    is_final: bool


@dataclass(frozen=True)
class Transition:
    from_status_id: str
    to_status_id: str
    required_permission: str | None


class TransitionError(Exception):
    pass


class StatusMachine:
    def __init__(self, statuses: list[Status], transitions: list[Transition]):
        self._statuses = {s.id: s for s in statuses}
        self._transitions = {
            (t.from_status_id, t.to_status_id): t for t in transitions
        }

    def initial(self) -> Status:
        for s in self._statuses.values():
            if s.is_initial:
                return s
        raise TransitionError("El tenant no tiene estado inicial configurado")

    def allowed_from(self, status_id: str) -> list[Status]:
        return [
            self._statuses[to_id]
            for (frm, to_id) in self._transitions
            if frm == status_id
        ]

    def validate(self, from_id: str, to_id: str, permissions: list[str]) -> None:
        if self._statuses[from_id].is_final:
            raise TransitionError("El pedido ya está en un estado final")

        transition = self._transitions.get((from_id, to_id))
        if transition is None:
            raise TransitionError("Transición no permitida para este restaurante")

        needed = transition.required_permission
        if needed and needed not in permissions:
            raise TransitionError(f"Falta el permiso: {needed}")
