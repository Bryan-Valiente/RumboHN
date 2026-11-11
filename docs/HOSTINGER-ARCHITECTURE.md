# Rumbo HN — arquitectura propuesta para Hostinger

Estado: diseño local para revisión. No existe todavía servicio, base de datos ni despliegue de producción.

## Decisión de infraestructura

Business y Cloud admiten aplicaciones Node.js. El alojamiento administrado de Hostinger ofrece MySQL; la conexión desde la aplicación Node.js alojada allí se configura mediante variables de entorno y `127.0.0.1`. La primera versión no dependerá de WebSockets ni de procesos de trabajo permanentes, que estos planes no ofrecen. Las operaciones largas deberán dividirse o ejecutarse mediante tareas programadas. Fuentes: [despliegue Node.js](https://www.hostinger.com/support/how-to-deploy-a-nodejs-website-in-hostinger/), [MySQL para Node.js](https://www.hostinger.com/support/connecting-a-hostinger-mysql-database-to-a-node-js-application/) y [límites Node.js](https://www.hostinger.com/support/hostinger-node-js-limits-for-web-and-cloud-plans/).

## Componentes

1. **Cliente React:** interfaz y validación para ayudar al usuario. No contiene secretos ni decide la propiedad de datos. La sección pública puede conservar ejemplos ficticios, identificados como tales.
2. **API Node.js:** recibe las peticiones financieras, valida entradas y sesión, autoriza cada operación, aplica reglas de negocio y responde con errores claros. Se serviría bajo el mismo origen que el cliente para simplificar cookies y despliegue.
3. **Autenticación:** propuesta de Better Auth con MySQL. Su documentación oficial admite el controlador MySQL y migraciones; la integración concreta, envío de correos, protección contra abuso, configuración de cookies y recuperación se verificarán con pruebas antes de habilitar cuentas. Fuentes: [instalación](https://better-auth.com/docs/installation) y [adaptador MySQL](https://better-auth.com/docs/adapters/mysql).
4. **MySQL:** datos de identidad y finanzas en tablas con claves foráneas, índices por usuario y migraciones versionadas. Los importes se guardan como enteros en centavos. Cada consulta financiera filtra por el ID de usuario de la sesión; también se verifican las relaciones entre entidades del mismo titular.
5. **Correo transaccional:** servicio por definir para verificación de correo y recuperación. No se habilitará el alta pública hasta comprobar entrega y flujo de recuperación.

## Entornos y despliegue

- Desarrollo y pruebas usan datos ficticios y una base separada de producción.
- El despliegue se conectará a una rama o referencia aprobada, nunca a cada push de `feature/owen`.
- Secretos en variables de entorno de Hostinger; `.env` local excluido de Git. La clave de sesión, credenciales MySQL y credenciales de correo no se imprimen en logs ni se exponen al cliente.
- Cambios de esquema mediante migraciones revisadas, con respaldo previo y ensayo de restauración. No se ejecutarán migraciones destructivas automáticamente al iniciar el servidor.
- HTTPS, cookies seguras y respaldos se comprobarán en un entorno de ensayo antes de aceptar datos reales.

## Primer incremento implementable

1. Separar la lógica financiera compartida de las semillas ficticias y definir contratos de entrada/salida con centavos enteros y fechas locales.
2. Crear API y esquema MySQL versionado para una cuenta y movimientos manuales. Integrar autenticación mantenida y cerrar operaciones sin sesión.
3. Probar con dos cuentas: registro, inicio y cierre de sesión, persistencia tras recarga, recuperación de acceso, acceso cruzado denegado, importes inválidos y reintentos.
4. Conectar primero movimientos y resumen real. Activar progresivamente presupuestos, metas, aportes y recordatorios, cada uno con sus pruebas.
5. Solo después de estas comprobaciones sustituir el acceso simulado y retirar las advertencias de datos ficticios en las pantallas conectadas.

## Límites de la primera versión

No habrá cobros, membresías, conexión bancaria, almacenamiento de credenciales bancarias, asesoría generada por IA ni notificaciones con la aplicación cerrada. Los módulos que aún no tengan servicio real seguirán claramente identificados como ejemplo o se ocultarán del flujo de producción.

## Decisiones que faltan para activar un entorno real

- Plan y dominio definitivos de Hostinger, junto con la persona administradora.
- Servicio y dirección de correo transaccional.
- Alcance exacto de los datos manuales, soporte, retención, eliminación y textos de privacidad.
- Revisión y autorización separada del despliegue de ensayo y del lanzamiento.
