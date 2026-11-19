# Rumbo HN — arquitectura propuesta para Hostinger

Estado: capacidades verificadas en B01-L02; backend preparado localmente y despliegue de producción no realizado.

## Decisión de infraestructura

El hosting asignado admite PHP 8.3 y Node.js. Para B01 se eligió una base PHP ligera porque PHP 8.3 y `nd_pdo_mysql` están activos en la cuenta y permiten una conexión PDO con consultas preparadas. La base dedicada usa MariaDB 11.8.9. La aplicación alojada debe conectarse por la ruta interna autorizada y variables de entorno; las credenciales nunca se incluyen en el repositorio ni en el navegador.

## Componentes

1. **Cliente React:** interfaz y validación para ayudar al usuario. No contiene secretos ni decide la propiedad de datos. La sección pública puede conservar ejemplos ficticios, identificados como tales.
2. **API PHP:** recibe las peticiones financieras, valida entradas y sesión, autoriza cada operación, aplica reglas de negocio y responde con envelopes JSON consistentes. Se prepara bajo `/api/v1` y debe servirse bajo el mismo origen aprobado del cliente.
3. **Autenticación:** la base incluye hashes de contraseña, sesiones con tokens hasheados, recuperación y rate limiting, pero los endpoints permanecen cerrados hasta implementar y probar el flujo completo y el proveedor de correo.
4. **MariaDB:** datos de identidad y finanzas en tablas InnoDB con claves foráneas, índices por usuario y migraciones versionadas. Los importes se guardan como HNL `DECIMAL(15,2)`. Cada consulta financiera debe filtrar por el ID derivado de la sesión y verificar relaciones del mismo titular.
5. **Correo transaccional:** servicio por definir para verificación de correo y recuperación. No se habilitará el alta pública hasta comprobar entrega y flujo de recuperación.

## Entornos y despliegue

- Desarrollo y pruebas usan datos ficticios y una base separada de producción.
- El despliegue se conectará a una rama o referencia aprobada, nunca a cada push de `feature/owen`.
- Secretos en variables de entorno de Hostinger; `.env` local excluido de Git. La clave de sesión, credenciales MySQL y credenciales de correo no se imprimen en logs ni se exponen al cliente.
- Cambios de esquema mediante migraciones revisadas, con respaldo previo y ensayo de restauración. No se ejecutarán migraciones destructivas automáticamente al iniciar el servidor.
- HTTPS, cookies seguras y respaldos se comprobarán en un entorno de ensayo antes de aceptar datos reales.

## Primer incremento implementable

1. Separar la lógica financiera compartida de las semillas ficticias y definir contratos de entrada/salida con cadenas HNL de dos decimales y fechas locales.
2. Completar los handlers PHP de autenticación y movimientos sobre el esquema versionado. Mantener cerradas todas las operaciones sin sesión.
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
