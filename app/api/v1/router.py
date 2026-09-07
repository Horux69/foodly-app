from fastapi import APIRouter

api_router = APIRouter()

# Los routers de cada modulo se registran aqui a medida que se construyen.
# Orden de construccion sugerido (ver docs/functional-scope.md):
#
# from app.api.v1 import auth, tenants, branches, users, menu, orders, \
#     kitchen, payments, delivery, customers, reports
#
# api_router.include_router(auth.router, prefix="/auth", tags=["auth"])
# api_router.include_router(menu.router, prefix="/menu", tags=["menu"])
# api_router.include_router(orders.router, prefix="/orders", tags=["orders"])
