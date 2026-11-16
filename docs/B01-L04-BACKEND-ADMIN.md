# RUMBO HN B01-L04 — backend financiero y panel administrativo

## Alcance

Esta entrega vive únicamente en la rama block/bryan-b01 y conserva el frontend de Owen sin modificaciones. La API PHP usa la base dedicada de RUMBO HN y mantiene aislamiento por user_id derivado de la sesión.

Los importes de entrada y salida son cadenas HNL con exactamente dos decimales. La persistencia continúa usando DECIMAL(15,2). No existe formato de unidades menores en el contrato, no se usan floats para cálculo financiero y cualquier equivalente USD sería únicamente informativo.

## Módulos completados

### Presupuestos

- GET, POST, PATCH y DELETE en /api/v1/budgets.
- Los egresos confirmados se asocian por user_id, categoría y fecha dentro del período del presupuesto.
- used y remaining se calculan con precisión HNL.
- La edición comprueba rangos de fecha y la clave única de categoría y período.
- DELETE elimina solamente el presupuesto del titular autenticado; no elimina movimientos.

### Metas de ahorro

- GET, POST, PATCH y DELETE en /api/v1/goals.
- DELETE archiva la meta y conserva sus aportes.
- GET y POST en /api/v1/goals/{goalId}/contributions.
- El aporte exige Idempotency-Key; una repetición equivalente devuelve el mismo recurso y otra carga con la misma clave produce conflicto.
- La meta devuelve target, contributed, remaining y progressPercent como porcentaje entero de presentación.
- preferredFrequency admite daily, weekly, biweekly, twice-monthly y monthly. La frecuencia twice-monthly exige dos días distintos.

### Obligaciones y recordatorios

- GET, POST, PATCH y DELETE en /api/v1/reminders.
- DELETE archiva y no borra el historial.
- GET en /api/v1/reminders/{reminderId}/payments.
- POST en /api/v1/reminders/{reminderId}/payments crea una sola financial_transaction confirmada de tipo expense, sourceType reminder-payment y un reminder_payment enlazado.
- Idempotency-Key evita dobles deducciones. El replay se resuelve antes de rechazar el recordatorio ya pagado.
- Recurrencias: one-time, weekly, every-15-days, twice-monthly, monthly y custom. Las fechas mensuales se fijan al último día disponible del mes y no se permite el salto de mes causado por una fecha como 31.
- Tarjetas solo guardan apodo, identificador enmascarado y fechas; nunca número completo, CVV ni credenciales bancarias.

### Notificaciones y reportes

- GET /api/v1/notifications admite unreadOnly y limit; PATCH /api/v1/notifications/{notificationId} cambia read a true o false.
- GET y PATCH /api/v1/notification-preferences conservan horarios, zona horaria y preferencias por tipo.
- La migración forward-only 002 agrega read_at e índice de lectura. No modifica ni reescribe 001.
- backend/bin/process-notifications.php crea recordatorios in-app deduplicados para resumen de mañana, revisión de tarde y obligaciones próximas. No envía correo ni usa un proveedor externo.
- GET /api/v1/reports/daily admite from, to y category. financial-summary conserva su respuesta anterior sin filtros; con esos parámetros devuelve el mismo núcleo financiero más filters.
- planned no reduce saldos confirmados. pending obligations se separa de totalBalance y projectedBalance.

## Panel administrativo

Ruta estática: /admin/. Sus datos solamente llegan de /api/v1/admin/*, cuyas rutas son access=admin. La sesión sigue usando cookie HttpOnly, SameSite=Strict y CSRF para mutaciones.

Incluye:

- Pulso operativo: usuarios registrados, activos, pendientes, trials activos, trials vencidos y cuentas restringidas.
- Cuentas: búsqueda, filtro por estado, activación con trial inicial de siete días, restauración, suspensión con revocación de sesiones y extensión controlada de trial de 1 a 30 días.
- Monitoreo: API, PDO/base, migraciones registradas y eventos de autenticación sin hashes de identidad.
- Auditoría: acciones administrativas, sujeto, request_id y fecha; nunca passwords, session tokens o payloads financieros.
- Responsive a 320, 390, 768 y escritorio mediante CSS propio, con lectura clara, navegación móvil y foco visible.

No existe un endpoint web para elevar roles. La provisión administrativa es solo CLI:

1. El operador carga RUMBO_ADMIN_EMAIL, RUMBO_ADMIN_PASSWORD y opcionalmente RUMBO_ADMIN_DISPLAY_NAME mediante el mecanismo de secretos del servidor.
2. Ejecuta php backend/bin/provision-admin.php desde el runtime privado, nunca desde public_html.
3. El comando rechaza una cuenta existente, activa una cuenta nueva, inicia su trial de siete días, asigna admin y no imprime la contraseña.
4. Se elimina el secreto temporal del mecanismo del operador después de la ejecución.

La recuperación y el registro público permanecen cerrados mientras no exista un proveedor de correo verificado. Eso no bloquea el acceso controlado de una cuenta administradora provisionada por CLI.

## Decisión visual

El panel usa el lenguaje ya presente en src/styles.css: navy #173447, papel #f5f6f1, mint #b9e6cb, amarillo #f5c85b, DM Sans/Manrope, divisores finos y una densidad de datos sobria. Se mantiene modo claro, un acento primario, colores semánticos y controles HTML accesibles; no se añadieron gradientes decorativos, iconos emoji ni una paleta violeta genérica.

Las búsquedas visuales live de Refero no estuvieron disponibles en este entorno por NO_SUBSCRIPTION, por lo que la referencia bloqueada es el sistema de diseño existente de RUMBO y las guías locales de contraste, tipografía, tokens y anti-AI-slop. No se inventó una identidad alternativa.
