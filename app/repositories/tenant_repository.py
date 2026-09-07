from sqlalchemy.orm import Session

from app.models.tenant import Tenant


def create(
    db: Session, *, name: str, business_type: str, currency: str = "COP", settings: dict | None = None
) -> Tenant:
    tenant = Tenant(name=name, business_type=business_type, currency=currency, settings=settings or {})
    db.add(tenant)
    db.flush()  # asigna tenant.id (RETURNING) antes de sembrar su configuracion
    return tenant
