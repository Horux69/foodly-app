"""Validacion de selecciones de modificadores contra su grupo.

Pura y testeable: recibe la configuracion ya resuelta (min_select/max_select
por grupo) y cuantos modificadores de ese grupo trae la linea del pedido.
"""

from dataclasses import dataclass


@dataclass(frozen=True)
class ModifierGroupConstraint:
    group_id: str
    name: str
    min_select: int
    max_select: int
    is_required: bool


class ModifierValidationError(Exception):
    pass


def validate_selection(constraint: ModifierGroupConstraint, selected_count: int) -> None:
    if constraint.is_required and selected_count == 0:
        raise ModifierValidationError(f"El grupo '{constraint.name}' es obligatorio")
    if selected_count < constraint.min_select:
        raise ModifierValidationError(
            f"El grupo '{constraint.name}' requiere mínimo {constraint.min_select} selección(es)"
        )
    if selected_count > constraint.max_select:
        raise ModifierValidationError(
            f"El grupo '{constraint.name}' permite máximo {constraint.max_select} selección(es)"
        )
