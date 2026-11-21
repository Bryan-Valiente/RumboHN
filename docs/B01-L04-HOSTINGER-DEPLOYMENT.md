# RUMBO HN B01-L04 — preparación y operación de Hostinger

## Destino autorizado

El único destino es el demo existente:

https://valienteinversionesgroup.com/demos/rumbo-hn/

La base existente es u149165412_asesor y el usuario dedicado es u149165412_rumbohn. No se crea otra base ni se modifican otros demos.

## Layout seguro

Public HTML contiene únicamente:

- el dist de Vite compilado con base /demos/rumbo-hn/;
- admin/index.html, admin/admin.css y admin/admin.js;
- api/index.php y el código PHP estrictamente necesario en api/src y api/config;
- los .htaccess de root y api.

El runtime completo, bin, migrations, backups y el archivo de entorno quedan fuera de public_html, por ejemplo en un directorio privado rumbo-hn-runtime. El PHP público recibe la ruta mediante RUMBO_RUNTIME_ROOT y el entorno mediante RUMBO_ENV_FILE. El .htaccess de api bloquea PHP distinto del front controller y extensiones de configuración, SQL y backup.

Variables obligatorias del entorno privado:

- RUMBO_APP_ENV=production
- RUMBO_ALLOWED_ORIGIN=https://valienteinversionesgroup.com
- RUMBO_BASE_PATH=/demos/rumbo-hn
- RUMBO_RUNTIME_ROOT=/ruta/privada/rumbo-hn-runtime
- RUMBO_ENV_FILE=/ruta/privada/rumbo-hn.env
- RUMBO_LOGIN_ENABLED=true para el demo controlado
- RUMBO_REGISTRATION_ENABLED=true únicamente en el laboratorio controlado autorizado
- RUMBO_REGISTRATION_MODE=admin_approval para el laboratorio sin servicio de correo; la activación administrativa no marca email_verified_at
- RUMBO_ACCOUNT_VERIFICATION_READY=false mientras no exista entrega real de correo
- RUMBO_PASSWORD_RECOVERY_ENABLED=false hasta disponer de correo real
- RUMBO_COOKIE_SECURE=true
- RUMBO_DB_NAME=u149165412_asesor
- RUMBO_DB_ALLOWED_NAME=u149165412_asesor
- RUMBO_DB_USER=u149165412_rumbohn
- RUMBO_DB_PASSWORD cargado por el operador y nunca versionado
- RUMBO_DB_HOST y RUMBO_SECURITY_PEPPER cargados por el operador desde el mecanismo autorizado

La contraseña de base, el pepper y cualquier secreto de correo no se documentan con su valor. El backend comprueba que la base seleccionada sea exactamente la autorizada.

## Backup antes de migration 002

Antes de tocar el esquema, el operador debe:

1. Confirmar en hPanel/phpMyAdmin que la base seleccionada es u149165412_asesor y que no se observan tablas ajenas.
2. Crear un directorio privado fuera de public_html, con permisos restringidos.
3. Ejecutar mysqldump desde SSH o la herramienta privada del hosting con --single-transaction, --routines, --triggers y --events; la contraseña se introduce mediante el mecanismo seguro del operador, no en el comando compartido.
4. Confirmar que el archivo no está vacío y guardar su SHA-256 en el mismo directorio privado.
5. Conservar el backup hasta terminar la verificación HTTP y la revisión del owner. No subirlo a Git ni a una URL.

La migration 002 es aditiva, usa ADD COLUMN IF NOT EXISTS, añade un índice y registra la versión. bin/migrate.php obtiene un lock MySQL y omite versiones ya registradas. No se debe ejecutar 001 de nuevo de forma manual.

## Despliegue

1. Construir el cliente desde esta rama con npm run build -- --base=/demos/rumbo-hn/. Esto no modifica src ni la rama de Owen; solo genera artefactos.
2. Copiar dist, backend/admin, backend/deploy/hostinger/root/.htaccess y backend/deploy/hostinger/api/.htaccess al directorio demo.
3. Copiar backend/src y backend/config al runtime privado equivalente a api/src y api/config. Copiar bin y migrations solamente al runtime privado.
4. Cargar el entorno privado y ejecutar `php bin/migrate.php` desde el runtime. La salida esperada es que cada versión pendiente se aplique una vez; no debe mostrar credenciales.
5. Ejecutar `php bin/provision-admin.php` solo con un TTY seguro: usa `RUMBO_ADMIN_USERNAME` (por defecto `rumbohn2026`) y solicita la contraseña con eco desactivado cuando no exista un secreto temporal autorizado. No usar correo falso ni guardar la contraseña en archivos públicos.
6. Confirmar HTTPS, cookie Secure, same-origin, GET /demos/rumbo-hn/api/v1/health y GET /demos/rumbo-hn/admin/.

## Smoke HTTP posterior

Sin datos reales ni payloads financieros:

- GET /demos/rumbo-hn/api/v1/health debe devolver 200 y database=available.
- GET /demos/rumbo-hn/admin/ debe devolver 200, title de administración y meta noindex.
- Un POST de login con credenciales inválidas debe devolver un error genérico, no enumerar cuentas y no revelar conexión.
- Una cuenta sin admin debe recibir 403 en /api/v1/admin/dashboard.
- La cuenta provisionada debe poder iniciar sesión, recibir cookies Secure/HttpOnly/SameSite y cargar el panel.
- El login administrativo debe usar `POST /demos/rumbo-hn/api/v1/admin/auth/login` con username; `POST /demos/rumbo-hn/api/v1/auth/login` conserva el contrato cliente por email.
- El owner debe verificar dashboard, búsqueda de cuentas, monitoreo, auditoría y navegación móvil.

La suite sintética local no prueba por sí sola el PHP hospedado. El estado deployed=PASS solo puede declararse después de observar esos HTTP checks y PDO directo desde el runtime real.

## Estado de integración con Owen

El frontend presente en dist sigue siendo el prototipo de cliente con persistencia/in-memory del trabajo de Owen y no se modifica en B01-L04. Puede copiarse como artefacto visual bajo el subdirectorio si el owner acepta esa separación. No se reporta como cliente conectado al backend.

La integración posterior debe:

- compilar ese frontend con la base del demo;
- reemplazar únicamente sus adaptadores de datos simulados por llamadas al contrato /api/v1;
- mantener HNL como strings y enviar Idempotency-Key en movimientos, aportes y pagos;
- ejecutar pruebas de onboarding, login, recarga, aislamiento, presupuesto, metas, obligaciones, notificaciones y reportes;
- permanecer en la rama y worktree de Owen hasta su revisión independiente.

## Registro real del laboratorio controlado

El frontend de esta actualización conserva el recorrido local cifrado cuando la persona elige explícitamente `Empezar gratis`, pero el tab `Crear espacio` del modal de autenticación usa `POST /api/v1/auth/register` con correo, nombre, contraseña de mínimo 12 caracteres, `America/Tegucigalpa` y `es-HN`. La cuenta queda pendiente hasta la activación desde `admin/`; no se muestra éxito si el servidor no confirma el registro. El modo `admin_approval` es solo para este laboratorio privado y debe volver a `RUMBO_REGISTRATION_ENABLED=false` antes de cualquier publicación fuera del entorno controlado.
