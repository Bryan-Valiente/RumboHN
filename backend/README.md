# RUMBO HN backend foundation

Estado: implementación local B01-L03. No está desplegada. Login, registro y recuperación permanecen deshabilitados por defecto mediante variables de entorno.

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
5. Generar `RUMBO_SECURITY_PEPPER` con entropía criptográfica y custodiarlo fuera del repositorio. Su pérdida invalida CSRF, hashes de identidad y cursores firmados.
6. En producción, `RUMBO_COOKIE_SECURE` se fuerza a `true` aunque una variable intente desactivarlo.

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

## API implementada localmente

- `GET /api/v1/health` es el único endpoint público operativo y no revela host, versión ni credenciales.
- Los seis endpoints `/api/v1/auth/*` están implementados. Login, registro y recuperación responden `AUTH_NOT_AVAILABLE` mientras sus compuertas sigan en `false`; registro exige además que la verificación de cuenta esté declarada lista y la recuperación exige un transporte real, que todavía no existe.
- Registro crea una cuenta `pending`; la activación y el inicio del trial de siete días se realizan por el servicio interno `AccountActivator`, sin endpoint público de escalamiento.
- Las sesiones usan tokens aleatorios administrados por el servidor, hash SHA-256 en base, cookie `HttpOnly`, `SameSite=Strict`, expiración y revocación. Las mutaciones autenticadas por cookie requieren el encabezado y cookie CSRF coincidentes.
- El rate limiting de login persiste hashes HMAC de identidad e IP en `auth_attempts`. Los errores de login y recuperación no revelan si existe una cuenta.
- `GET/PATCH /api/v1/me/profile`, CRUD de `/api/v1/movements` y `GET /api/v1/financial-summary` están implementados y obtienen el titular exclusivamente de la sesión.
- `POST /api/v1/movements` requiere `Idempotency-Key`. El borrado es lógico y un ID ajeno responde como no encontrado.
- Las rutas de presupuestos, metas, recordatorios, reportes y notificaciones conservan el contrato pero siguen respondiendo `NOT_IMPLEMENTED` después de autenticar.
- Los tokens de sesión se almacenan únicamente como SHA-256 binario; las contraseñas usan Argon2id cuando está disponible.
- Los importes JSON se reciben y devuelven como cadenas decimales HNL con dos posiciones, por ejemplo `"15000.00"`. No se usan floats ni `amountCents`.

## Validación local

```bash
php tests/run.php
```

La suite usa una base SQLite en memoria para pruebas sintéticas aisladas cuando `pdo_sqlite` está disponible. No reemplaza la validación posterior contra MariaDB en staging. Además, ejecutar `php -l` sobre cada archivo PHP antes de cualquier despliegue.

`tests/mariadb_integration.php` añade un smoke test opcional sobre repositorios SQL reales. Tiene una barrera estricta: solo ejecuta con `RUMBO_APP_ENV=test`, host `127.0.0.1`/`localhost` y una base cuyo nombre termine en `_test`. La creación y eliminación de esa base se administran fuera del script.

No ejecutar estas rutas contra la base Hostinger como sustituto de un ambiente de integración. B01-L03 no despliega ni habilita cuentas públicas.
