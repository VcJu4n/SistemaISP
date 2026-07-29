# SistemaISP API

Backend de SistemaISP construido con Laravel 12, PHP 8.3+, PostgreSQL, Eloquent ORM y Laravel Sanctum.

El alcance actual contempla un único administrador. No incluye registro público, roles, facturación, tickets ni auditoría.

## Instalación

1. Instala PHP 8.3 o superior, Composer y PostgreSQL.
2. Copia `.env.example` a `.env` y configura la conexión `DB_*`.
3. Define `ADMIN_NAME`, `ADMIN_EMAIL` y una contraseña segura en `ADMIN_PASSWORD`.
4. Ejecuta:

```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Después del primer seeding puedes retirar `ADMIN_PASSWORD` del archivo `.env`.

## Autenticación

- `POST /api/auth/login`: recibe `email`, `password` y opcionalmente `device_name`.
- `GET /api/auth/me`: requiere `Authorization: Bearer <token>`.
- `POST /api/auth/logout`: requiere el mismo token y lo revoca.

## Pruebas

```bash
php artisan test
```
