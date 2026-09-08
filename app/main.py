from pathlib import Path

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles

from app.core.config import settings
from app.api.v1.router import api_router

WEB_DIR = Path(__file__).resolve().parent.parent / "web"

app = FastAPI(
    title="Plataforma de Restaurantes",
    description="API multi-tenant para gestion de restaurantes",
    version="0.1.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # restringir en produccion
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(api_router, prefix=settings.API_V1_PREFIX)


@app.get("/health", tags=["system"])
def health() -> dict:
    return {"status": "ok", "environment": settings.ENVIRONMENT}


class WebEstatico(StaticFiles):
    """Sirve el frontend obligando a revalidar en cada carga.

    Sin esto el navegador se queda con el CSS y los modulos JS que ya tiene y
    despues de un despliegue la gente sigue viendo la version anterior, sin
    forma de saberlo salvo recargando a la fuerza. Como no hay paso de build,
    tampoco hay nombres con hash que rompan la cache solos.

    `no-cache` no significa "no guardes": el archivo se guarda igual y se
    revalida con su ETag, asi que lo normal sigue siendo un 304 sin cuerpo.
    """

    def is_not_modified(self, response_headers, request_headers) -> bool:
        response_headers.setdefault("cache-control", "no-cache")
        return super().is_not_modified(response_headers, request_headers)

    async def get_response(self, path: str, scope):
        response = await super().get_response(path, scope)
        response.headers.setdefault("cache-control", "no-cache")
        return response


# El frontend se sirve desde el mismo origen que la API: sin CORS de por
# medio y sin paso de build. Va al final para no tapar /health ni /api.
app.mount("/", WebEstatico(directory=WEB_DIR, html=True), name="web")
