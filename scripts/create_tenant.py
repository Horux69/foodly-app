"""Alta de un restaurante nuevo en la plataforma.

Crear un tenant es una operacion de plataforma, no de tenant: no existe
todavia un token del cual sacar el tenant_id, y el modelo de seguridad no
contempla un usuario que cruce empresas (ver CLAUDE.md, principio 1). Por
eso vive en un script y no en un endpoint.

Deja el restaurante listo para operar: estados segun su modelo de negocio,
rol admin, impuesto por defecto, primera sucursal y primer usuario.

    python scripts/create_tenant.py "Pizza Napoli" \
        --business-type table_service \
        --branch "Sede Norte" --branch-code NOR \
        --admin-email dueno@napoli.com --admin-password "una-clave-larga"
"""

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

# Conexion administrativa: crear una empresa es previo a que exista tenant
# alguno, asi que no puede pasar por el rol restringido que sirve requests.
from app.core.database import AdminSessionLocal  # noqa: E402
from app.core.security import hash_password  # noqa: E402
from app.domain.tenant_settings import DEFAULTS_BY_BUSINESS_TYPE  # noqa: E402
from app.repositories import branch_repository, role_repository, user_repository  # noqa: E402
from app.services.tenant_provisioning import create_tenant  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Da de alta un restaurante nuevo")
    parser.add_argument("name", help="Nombre del restaurante")
    parser.add_argument(
        "--business-type",
        default="fast_food",
        choices=sorted(DEFAULTS_BY_BUSINESS_TYPE),
        help="Define el flujo de estados y la configuracion inicial",
    )
    parser.add_argument("--currency", default="COP")
    parser.add_argument("--branch", required=True, help="Nombre de la primera sucursal")
    parser.add_argument("--branch-code", required=True, help="Codigo corto, prefijo del numero de pedido")
    parser.add_argument("--timezone", default="America/Bogota")
    parser.add_argument("--admin-name", default="Administrador")
    parser.add_argument("--admin-email", required=True)
    parser.add_argument("--admin-password", required=True)
    args = parser.parse_args()

    if len(args.admin_password) < 8:
        print("La clave del administrador debe tener al menos 8 caracteres", file=sys.stderr)
        return 1

    db = AdminSessionLocal()
    try:
        tenant = create_tenant(
            db, name=args.name, business_type=args.business_type, currency=args.currency
        )

        branch = branch_repository.create(
            db,
            tenant_id=tenant.id,
            name=args.branch,
            code=args.branch_code,
            timezone=args.timezone,
            address=None,
            phone=None,
        )

        admin_role = role_repository.get_by_code(db, tenant.id, "admin")
        if admin_role is None:
            print("El aprovisionamiento no dejo un rol admin; se aborta", file=sys.stderr)
            db.rollback()
            return 1

        user_repository.create(
            db,
            tenant_id=tenant.id,
            role_id=admin_role.id,
            branch_id=branch.id,
            name=args.admin_name,
            email=args.admin_email,
            password_hash=hash_password(args.admin_password),
        )
        db.commit()

        # commit() expira los atributos: hay que leerlos antes de cerrar la
        # sesion o el refresh perezoso reventaria contra una sesion muerta.
        summary = (str(tenant.id), branch.name, branch.code)
    except Exception as exc:
        db.rollback()
        print(f"No se pudo crear el restaurante: {exc}", file=sys.stderr)
        return 1
    finally:
        db.close()

    tenant_id, branch_name, branch_code = summary
    print(f"Restaurante '{args.name}' creado")
    print(f"  tenant_id : {tenant_id}")
    print(f"  sucursal  : {branch_name} ({branch_code})")
    print(f"  admin     : {args.admin_email}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
