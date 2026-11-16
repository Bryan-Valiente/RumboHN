# RUMBO HN backend foundation

Estado: base técnica B01-L02. No está desplegada y no habilita registro, inicio de sesión ni operaciones financieras públicas.

## Runtime confirmado

- Hostinger: PHP 8.3 activo.
- Extensión Hostinger `nd_pdo_mysql`: activa.
- Base: MariaDB 11.8.9, `utf8mb4_unicode_ci`.
- Desarrollo local validado con PHP 8.2 y PDO.

## Configuración

1. Copiar `.env.example` como `.env` fuera de Git y completar los valores por un canal seguro.
2. Mantener `RUMBO_DB_NAME` y `RUMBO_DB_ALLOWED_NAME` idénticos. La conexión falla si no coinciden o si el servidor selecciona otra base.
3. No ubicar `.env` dentro de `public/`. También se puede indicar una ruta externa mediante `RUMBO_ENV_FILE`.
4. No imprimir la contraseña ni activar `RUMBO_DEBUG` en producción.

El backend no habilita CORS abierto. Si llega un encabezado `Origin`, debe coincidir exactamente con `RUMBO_ALLOWED_ORIGIN`.

## Migraciones

Comprobación local, sin conexión:

```bash
php bin/migrate.php --dry-run
```

Aplicación por CLI, solamente con variables seguras cargadas:

```bash
php bin/migrate.php
```

La migración `001_initial_schema.sql` es aditiva: no contiene `DROP`, `TRUNCATE`, `RENAME` ni creación de bases. Usa InnoDB, claves foráneas, HNL en `DECIMAL(15,2)`, ownership por usuario e idempotencia.

Para phpMyAdmin: seleccionar primero la base RUMBO exacta, confirmar que no contiene tablas inesperadas, abrir **Importar**, cargar únicamente `001_initial_schema.sql`, mantener UTF-8 y ejecutar. Después verificar `schema_migrations`, tablas, vistas, motores y claves foráneas. Nunca importar en otra base del hosting compartido.

## API preparada

- `GET /api/v1/health` es el único endpoint público operativo y no revela host, versión ni credenciales.
- Registro, login y recuperación responden `AUTH_NOT_AVAILABLE` hasta completar controles, correo y rate limiting.
- Todas las rutas financieras autentican primero; sin un bearer token válido responden `AUTH_REQUIRED` o `AUTH_INVALID`.
- Aun con sesión válida, los handlers financieros responden `NOT_IMPLEMENTED` hasta su loop de implementación.
- Los tokens de sesión se almacenarán únicamente como SHA-256 binario; las contraseñas usan Argon2id cuando está disponible.
- Los importes JSON se reciben y devuelven como cadenas decimales HNL con dos posiciones, por ejemplo `"15000.00"`. No se usan floats ni `amountCents`.

## Validación local

```bash
php tests/run.php
```

Además, ejecutar `php -l` sobre cada archivo PHP antes de cualquier despliegue.
