# O01 — integración del cliente con B01

## Alcance implementado

El cliente conserva el espacio cifrado local como modo predeterminado. Para construir una vista de cuenta conectada contra la API de Bryan, definir `VITE_RUMBO_CLIENT_MODE=api` y compilar con la base del destino, por ejemplo `npm run build -- --base=/demos/rumbo-hn/`. La vista conectada no importa ni borra los registros del navegador local.

La primera vista conectada usa sesión y login por correo, perfil, resumen financiero autoritativo y movimientos paginados con alta, edición y baja. Los importes viajan como cadenas decimales HNL; la creación envía `Idempotency-Key` y reutiliza la misma clave si se reintenta un envío idéntico después de un fallo de red. El transporte conserva la cookie del mismo origen y agrega `X-CSRF-Token` desde la respuesta de sesión o login a las mutaciones. Los recursos de la PWA respetan la base del subdirectorio y la caché no intercepta la API.

El modo conectado es un recorrido inicial de O01, no un reemplazo de todas las pantallas locales. Presupuestos, metas, recordatorios, notificaciones y reportes todavía necesitan su integración de interfaz, pruebas de estados y validación contra cuentas aisladas. Registro y recuperación requieren las compuertas y el transporte de correo de B01; no se presentan como disponibles.

## Bloqueo de integración en B01

`backend/src/Auth/SessionCookieManager.php` fija la ruta de la cookie de sesión en `/api/v1`, mientras el despliegue documentado usa `RUMBO_BASE_PATH=/demos/rumbo-hn`. En ese destino las solicitudes van a `/demos/rumbo-hn/api/v1/...`; el navegador no enviará una cookie limitada a `/api/v1`. Bryan debe hacer que la ruta de la cookie incluya el subdirectorio configurado, actualizar la limpieza de cookie y probar login → sesión → mutación → logout en el origen real o un staging equivalente. Owen no modifica el backend en este bloque.

## Validación pendiente para la PR de integración

1. Corregir y verificar la ruta de cookie en B01; disponer de una cuenta de prueba activa sin usar datos reales.
2. Construir ambos bloques juntos bajo la base del destino y ejecutar frontend y suite PHP.
3. Probar en navegador login, recarga, CSRF, movimientos, idempotencia, paginación, aislamiento de dos cuentas, errores y cierre de sesión.
4. Revisar las pantallas locales restantes antes de activar el modo API para todas las personas.

El endpoint de salud del demo responde, pero no demuestra por sí solo que las operaciones autenticadas funcionen.
