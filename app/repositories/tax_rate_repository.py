import uuid
from decimal import Decimal

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from app.models.tax_rate import TaxRate


def get(db: Session, tenant_id: uuid.UUID, tax_rate_id: uuid.UUID) -> TaxRate | None:
    stmt = select(TaxRate).where(TaxRate.tenant_id == tenant_id, TaxRate.id == tax_rate_id)
    return db.scalars(stmt).first()


def get_default(db: Session, tenant_id: uuid.UUID) -> TaxRate | None:
    stmt = select(TaxRate).where(TaxRate.tenant_id == tenant_id, TaxRate.is_default.is_(True))
    return db.scalars(stmt).first()


def list_for_tenant(db: Session, tenant_id: uuid.UUID) -> list[TaxRate]:
    stmt = select(TaxRate).where(TaxRate.tenant_id == tenant_id).order_by(TaxRate.name)
    return list(db.scalars(stmt))


def clear_default(db: Session, tenant_id: uuid.UUID) -> None:
    """Hay un indice unico parcial de un solo default por tenant: sin limpiar
    el anterior, marcar uno nuevo revienta contra la base."""
    db.execute(
        update(TaxRate)
        .where(TaxRate.tenant_id == tenant_id, TaxRate.is_default.is_(True))
        .values(is_default=False)
    )
    db.flush()


def create(
    db: Session,
    *,
    tenant_id: uuid.UUID,
    name: str,
    rate: Decimal,
    included_in_price: bool,
    is_default: bool,
) -> TaxRate:
    tax_rate = TaxRate(
        tenant_id=tenant_id,
        name=name,
        rate=rate,
        included_in_price=included_in_price,
        is_default=is_default,
    )
    db.add(tax_rate)
    db.flush()
    return tax_rate
