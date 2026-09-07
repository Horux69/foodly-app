from fastapi import APIRouter

from app.api.v1 import auth, kitchen, menu, orders, payments

api_router = APIRouter()

api_router.include_router(auth.router, prefix="/auth", tags=["auth"])
api_router.include_router(menu.router, prefix="/menu", tags=["menu"])
api_router.include_router(orders.router, prefix="/orders", tags=["orders"])
api_router.include_router(payments.router, prefix="/orders", tags=["caja y pagos"])
api_router.include_router(kitchen.router, prefix="/kitchen", tags=["cocina"])

# Resto de modulos se registran aqui a medida que se construyen.
# Orden de construccion sugerido (ver docs/functional-scope.md):
#
# from app.api.v1 import tenants, branches, users, delivery, customers, reports
