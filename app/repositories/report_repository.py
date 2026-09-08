"""Consultas agregadas de reportes.

Dos decisiones que atraviesan todas las consultas:

- Se clasifica por `order_statuses.category`, nunca por `code`: cada
  restaurante nombra sus estados distinto pero la categoria es la parte
  normalizada (ver CLAUDE.md).
- Las fechas se resuelven en la zona horaria de cada sucursal
  (`branches.timezone`), no en UTC: un pedido de las 11pm en Bogota
  pertenece a ese dia, no al siguiente.
"""

import uuid
from datetime import date

from sqlalchemy import text
from sqlalchemy.orm import Session

# Un pedido cuenta como venta cuando llego a un estado de categoria
# 'completed'. Los cancelados no son venta y los abiertos todavia no lo son.
#
# Se usa CAST(:branch_id AS uuid) y no `:branch_id::uuid` porque text() de
# SQLAlchemy no reconoce como parametro un nombre seguido de ':' (lo confunde
# con el operador de cast de Postgres) y lo dejaria literal en el SQL.
_SOLD = """
    JOIN order_statuses s ON s.id = o.status_id
    JOIN branches b ON b.id = o.branch_id
    WHERE o.tenant_id = :tenant_id
      AND s.category = 'completed'
      AND (CAST(:branch_id AS uuid) IS NULL OR o.branch_id = CAST(:branch_id AS uuid))
      AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
"""


def _params(tenant_id: uuid.UUID, branch_id: uuid.UUID | None, from_date: date, to_date: date) -> dict:
    return {
        "tenant_id": tenant_id,
        "branch_id": branch_id,
        "from_date": from_date,
        "to_date": to_date,
    }


def sales_totals(db: Session, tenant_id, branch_id, from_date, to_date) -> dict:
    sql = text(f"""
        SELECT count(*) AS orders,
               coalesce(sum(o.total), 0) AS revenue,
               round(coalesce(avg(o.total), 0), 2) AS avg_ticket
        FROM orders o {_SOLD}
    """)
    return dict(db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings().one())


def sales_by_day(db: Session, tenant_id, branch_id, from_date, to_date) -> list[dict]:
    sql = text(f"""
        SELECT (o.created_at AT TIME ZONE b.timezone)::date AS day,
               count(*) AS orders,
               sum(o.total) AS revenue
        FROM orders o {_SOLD}
        GROUP BY 1
        ORDER BY 1
    """)
    return [dict(r) for r in db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings()]


def sales_by_channel(db: Session, tenant_id, branch_id, from_date, to_date) -> list[dict]:
    sql = text(f"""
        SELECT o.channel, count(*) AS orders, sum(o.total) AS revenue
        FROM orders o {_SOLD}
        GROUP BY o.channel
        ORDER BY revenue DESC
    """)
    return [dict(r) for r in db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings()]


def sales_by_branch(db: Session, tenant_id, branch_id, from_date, to_date) -> list[dict]:
    sql = text(f"""
        SELECT b.id AS branch_id, b.name AS branch_name, count(*) AS orders, sum(o.total) AS revenue
        FROM orders o {_SOLD}
        GROUP BY b.id, b.name
        ORDER BY revenue DESC
    """)
    return [dict(r) for r in db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings()]


def top_products(db: Session, tenant_id, branch_id, from_date, to_date, limit: int) -> list[dict]:
    """Agrupa por menu_item_id y no por name_snapshot: si el producto se
    renombro a mitad del periodo sus ventas deben sumar juntas."""
    sql = text(f"""
        SELECT oi.menu_item_id,
               mi.name,
               sum(oi.quantity) AS units,
               sum(oi.line_total) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN menu_items mi ON mi.id = oi.menu_item_id
        {_SOLD}
        GROUP BY oi.menu_item_id, mi.name
        ORDER BY units DESC
        LIMIT :limit
    """)
    params = _params(tenant_id, branch_id, from_date, to_date) | {"limit": limit}
    return [dict(r) for r in db.execute(sql, params).mappings()]


def prep_times(db: Session, tenant_id, branch_id, from_date, to_date) -> dict:
    """Minutos entre que el pedido entra a cocina y queda listo, leidos de
    order_status_history. Incluye mediana porque el promedio se distorsiona
    con un solo pedido olvidado."""
    sql = text("""
        WITH marks AS (
            SELECT h.order_id,
                   min(h.changed_at) FILTER (WHERE s.category = 'kitchen') AS started,
                   min(h.changed_at) FILTER (WHERE s.category = 'ready') AS ready
            FROM order_status_history h
            JOIN order_statuses s ON s.id = h.status_id
            JOIN orders o ON o.id = h.order_id
            JOIN branches b ON b.id = o.branch_id
            WHERE o.tenant_id = :tenant_id
              AND (CAST(:branch_id AS uuid) IS NULL OR o.branch_id = CAST(:branch_id AS uuid))
              AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
            GROUP BY h.order_id
        ), minutes AS (
            SELECT EXTRACT(EPOCH FROM (ready - started)) / 60 AS m
            FROM marks
            WHERE started IS NOT NULL AND ready IS NOT NULL
        )
        SELECT count(*) AS orders,
               avg(m) AS avg_minutes,
               percentile_cont(0.5) WITHIN GROUP (ORDER BY m) AS median_minutes,
               min(m) AS min_minutes,
               max(m) AS max_minutes
        FROM minutes
    """)
    return dict(db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings().one())


def peak_hours(db: Session, tenant_id, branch_id, from_date, to_date) -> list[dict]:
    """Mide demanda, no venta: cuenta todo lo que no fue cancelado, incluidos
    los pedidos aun abiertos. Por eso no reutiliza el filtro de ventas."""
    sql = text("""
        SELECT EXTRACT(HOUR FROM (o.created_at AT TIME ZONE b.timezone))::int AS hour,
               count(*) AS orders,
               sum(o.total) AS revenue
        FROM orders o
        JOIN order_statuses s ON s.id = o.status_id
        JOIN branches b ON b.id = o.branch_id
        WHERE o.tenant_id = :tenant_id
          AND s.category <> 'cancelled'
          AND (CAST(:branch_id AS uuid) IS NULL OR o.branch_id = CAST(:branch_id AS uuid))
          AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
        GROUP BY 1
        ORDER BY 1
    """)
    return [dict(r) for r in db.execute(sql, _params(tenant_id, branch_id, from_date, to_date)).mappings()]
