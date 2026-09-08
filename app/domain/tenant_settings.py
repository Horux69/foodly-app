"""Motor de configuracion por tenant (modulo 9).

Traduce el JSONB de `tenants.settings` a una configuracion tipada, con
valores por defecto segun el modelo de negocio. Es el UNICO lugar que sabe
interpretar ese JSON: el resto de la app pregunta aqui en vez de leer
claves sueltas del diccionario.
"""

from dataclasses import dataclass

# Canales que entiende la plataforma. 'whatsapp' ya existe para cuando entre
# el agente conversacional; que este disponible no significa que un tenant
# lo tenga activo.
CHANNELS = ("counter", "table", "delivery", "whatsapp", "app")

DEFAULTS_BY_BUSINESS_TYPE: dict[str, dict] = {
    "fast_food": {"channels": ["counter", "delivery"], "uses_tables": False, "asks_tip": False},
    "table_service": {"channels": ["table"], "uses_tables": True, "asks_tip": True},
    "delivery": {"channels": ["delivery", "whatsapp"], "uses_tables": False, "asks_tip": False},
}

_FALLBACK = "fast_food"


class SettingsError(Exception):
    pass


@dataclass(frozen=True)
class TenantSettings:
    channels: tuple[str, ...]
    uses_tables: bool
    asks_tip: bool

    def allows_channel(self, channel: str) -> bool:
        return channel in self.channels


def defaults_for(business_type: str) -> dict:
    return dict(DEFAULTS_BY_BUSINESS_TYPE.get(business_type, DEFAULTS_BY_BUSINESS_TYPE[_FALLBACK]))


def validate(raw: dict) -> None:
    """Valida la forma del JSON antes de guardarlo.

    Solo revisa las claves presentes: un tenant puede guardar configuracion
    parcial y el resto se resuelve con los defaults de su business_type.
    """
    if not isinstance(raw, dict):
        raise SettingsError("La configuracion debe ser un objeto")

    if "channels" in raw:
        channels = raw["channels"]
        if not isinstance(channels, list) or not channels:
            raise SettingsError("'channels' debe ser una lista con al menos un canal")
        unknown = [c for c in channels if c not in CHANNELS]
        if unknown:
            raise SettingsError(f"Canales desconocidos: {unknown}. Válidos: {list(CHANNELS)}")

    for flag in ("uses_tables", "asks_tip"):
        if flag in raw and not isinstance(raw[flag], bool):
            raise SettingsError(f"'{flag}' debe ser true o false")

    # Coherencia: vender por mesa exige manejar mesas. Sin esto quedaria un
    # tenant que acepta pedidos de mesa pero declara que no tiene mesas.
    if raw.get("channels") and "table" in raw["channels"] and raw.get("uses_tables") is False:
        raise SettingsError("El canal 'table' requiere uses_tables en true")


def parse(raw: dict | None, *, business_type: str) -> TenantSettings:
    data = defaults_for(business_type) | (raw or {})
    return TenantSettings(
        channels=tuple(data["channels"]),
        uses_tables=bool(data["uses_tables"]),
        asks_tip=bool(data["asks_tip"]),
    )
