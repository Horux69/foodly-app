from fastapi import APIRouter

from app.api.v1 import admin, auth, customers, delivery, kitchen, menu, orders, payments, reports

api_router = APIRouter()

api_router.include_router(auth.router, prefix="/auth", tags=["auth"])
api_router.include_router(admin.router)
api_router.include_router(menu.router, prefix="/menu", tags=["menu"])
api_router.include_router(orders.router, prefix="/orders", tags=["orders"])
api_router.include_router(payments.router, prefix="/orders", tags=["caja y pagos"])
api_router.include_router(kitchen.router, prefix="/kitchen", tags=["cocina"])
api_router.include_router(reports.router, prefix="/reports", tags=["reportes"])
api_router.include_router(delivery.router)
api_router.include_router(customers.router, prefix="/customers", tags=["clientes"])
