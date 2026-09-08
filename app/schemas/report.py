import uuid
from datetime import date
from decimal import Decimal

from pydantic import BaseModel


class SalesTotalsOut(BaseModel):
    orders: int
    revenue: Decimal
    avg_ticket: Decimal


class SalesByDayOut(BaseModel):
    day: date
    orders: int
    revenue: Decimal


class SalesByChannelOut(BaseModel):
    channel: str
    orders: int
    revenue: Decimal


class SalesByBranchOut(BaseModel):
    branch_id: uuid.UUID
    branch_name: str
    orders: int
    revenue: Decimal


class SalesReportOut(BaseModel):
    from_date: date
    to_date: date
    totals: SalesTotalsOut
    by_day: list[SalesByDayOut]
    by_channel: list[SalesByChannelOut]
    by_branch: list[SalesByBranchOut]


class TopProductOut(BaseModel):
    menu_item_id: uuid.UUID
    name: str
    units: int
    revenue: Decimal


class PrepTimesOut(BaseModel):
    orders: int
    avg_minutes: float | None
    median_minutes: float | None
    min_minutes: float | None
    max_minutes: float | None


class PeakHourOut(BaseModel):
    hour: int
    orders: int
    revenue: Decimal
