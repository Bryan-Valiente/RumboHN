# Rumbo HN — contrato inicial de datos

Estado: borrador para revisión; no se ha creado una base de datos ni se han importado datos reales.

## Alcance del primer lanzamiento

Cada cuenta es dueña de sus movimientos manuales, presupuestos, metas, aportes y recordatorios. No se recopilan números completos de tarjeta, CVV, contraseñas bancarias ni credenciales de terceros. Membresías y pagos quedan fuera de esta versión.

| Entidad | Campos principales | Regla de propiedad |
|---|---|---|
| Perfil | ID de usuario, zona horaria, preferencias | Solo el titular puede leer o cambiar su perfil. |
| Movimiento | ID, usuario, tipo, nombre, categoría, importe en centavos, fecha, estado confirmado o planificado | Solo el titular puede crear, leer, editar o borrar. |
| Presupuesto | ID, usuario, categoría, límite en centavos, periodo | Solo el titular; categoría y periodo deben ser únicos por usuario. |
| Meta | ID, usuario, nombre, objetivo en centavos, fecha límite, frecuencia | Solo el titular; objetivo positivo. |
| Aporte | ID, usuario, meta, importe en centavos, fecha, clave de operación | Solo el titular; la meta debe pertenecer al mismo usuario. |
| Recordatorio | ID, usuario, nombre, vencimiento, recurrencia, estado, importe esperado | Solo el titular; marcar pagado no crea un movimiento automáticamente. |

## Reglas financieras

1. El saldo total se calcula con saldo inicial + ingresos confirmados − gastos confirmados. Los movimientos planificados no lo afectan.
2. El dinero disponible para gastar es el saldo total menos los aportes separados para metas. Un aporte no se registra también como gasto.
3. Los importes persistidos son enteros en centavos de lempira; no se aceptan `NaN`, infinitos ni importes negativos donde el tipo de operación ya representa el signo.
4. Las altas y ediciones se validan en el servidor, además de la interfaz. Los IDs de usuario se derivan de la sesión autenticada, no de un campo enviado por el cliente.
5. Un reintento de la misma operación no duplica un movimiento o aporte. Las actualizaciones que afecten dos entidades se aplican de forma atómica.
6. Los presupuestos y los informes se derivan de movimientos confirmados del periodo y la zona horaria del usuario.
7. Las fechas se almacenan con semántica explícita: fecha local para movimientos y vencimientos; marca de tiempo UTC para creación y edición.

## Pruebas de aceptación de la base de datos

- Cuenta A no puede leer, crear, editar ni borrar registros de cuenta B, incluso si conoce sus IDs.
- Una sesión vencida no puede modificar datos.
- Recargar y volver a iniciar sesión conserva los registros del titular.
- Un fallo entre aporte y actualización de meta no deja los saldos inconsistentes.
- Importes extremos, fechas inválidas y duplicados se rechazan sin alterar el saldo.
- Se prueba una restauración de respaldo antes de aceptar usuarios reales.

El esquema y los controles de acceso se implementarán para MySQL en Hostinger Business o Cloud, tras identificar la cuenta administradora y el ambiente de desarrollo. La API impondrá la propiedad en cada operación y el servidor obtendrá el ID del usuario de la sesión. Ningún dato ficticio actual se migrará como si perteneciera a una persona real.
