"""Catalogo global de permisos del sistema.

Los permisos son fijos (los define la plataforma). Los roles que los
agrupan son configurables por tenant.
"""

PERMISSIONS: dict[str, str] = {
    # Configuracion
    "settings.view": "Ver configuracion del restaurante",
    "settings.edit": "Editar configuracion del restaurante",
    "branches.manage": "Gestionar sucursales",
    "users.manage": "Gestionar usuarios y roles",
    # Menu
    "menu.view": "Ver el menu",
    "menu.edit": "Crear y editar productos y categorias",
    "menu.availability": "Marcar productos como agotados",
    # Pedidos
    "orders.create": "Crear pedidos",
    "orders.view": "Ver pedidos",
    "orders.edit": "Modificar pedidos abiertos",
    "orders.cancel": "Cancelar pedidos",
    "orders.advance_kitchen": "Avanzar estados de cocina",
    "orders.discount": "Aplicar descuentos",
    # Caja
    "payments.register": "Registrar pagos",
    "payments.refund": "Anular o reembolsar pagos",
    "cash.close": "Cerrar turno y arqueo",
    # Domicilios
    "delivery.assign": "Asignar repartidores",
    "delivery.complete": "Confirmar entregas",
    # Clientes
    "customers.view": "Ver la base de clientes y su historial",
    "customers.manage": "Editar los datos de un cliente",
    # Reportes
    "reports.view": "Ver reportes",
}
