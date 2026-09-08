from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Conexion administrativa: dueña de las tablas. Se usa para migraciones y
    # para dar de alta empresas, que es una operacion previa a cualquier tenant.
    DATABASE_URL: str
    # Conexion con la que la app sirve requests. Debe ser un rol que NO sea
    # dueño de las tablas: Postgres saltea RLS para el dueño, asi que sin esto
    # las politicas no protegen nada. Si falta, se cae a DATABASE_URL y el
    # aislamiento queda solo en manos de los filtros por tenant_id.
    APP_DATABASE_URL: str | None = None
    SECRET_KEY: str
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 480
    ENVIRONMENT: str = "development"
    DEBUG: bool = True
    API_V1_PREFIX: str = "/api/v1"


settings = Settings()
