# CRÍTICO — Un usuario sin organización lee y modifica los datos clínicos de TODAS

## Qué pasó

El filtrado por organización sólo se aplica cuando hay una organización activa. Un
usuario que no pertenece a ninguna nunca activa ese contexto, y entonces **no se
agrega ninguna condición a las consultas**: obtiene las filas de todas las
organizaciones del sistema.

Verificado sobre 13 recursos, incluidos niños, visitas, tamizajes, cuidadores, datos de
nacimiento, centros y seguimientos. **No es sólo lectura**: también puede editar,
borrar e invalidar registros ajenos, y las escrituras quedan registradas a su nombre.

El listado nominal y los indicadores filtran igual de mal: devuelven nombre, CURP y
resultado de menores de otras organizaciones. Además evita la verificación de
suscripción, porque tampoco hay organización que verificar.

## Qué esperaba

Que la ausencia de organización **falle cerrada**: sin acceso, o resultado vacío.
Nunca sin filtro.

## Pasos para reproducir

1. Un super admin crea un usuario sin asignarle organización (el alta lo permite).
2. Ese usuario inicia sesión con normalidad.
3. Consulta el listado de niños → recibe los de todas las organizaciones, con CURP.
4. Edita o borra el registro de un niño de otra organización → la operación se aplica.

## Cómo se llega a este estado sin querer

- Un usuario creado sin organización, que el alta acepta sin objetar.
- **Al eliminar una organización**, la relación de sus usuarios se borra en cascada:
  todos quedan en este estado mientras su sesión siga viva.
- Cualquier flujo futuro de "quitar a un usuario de una organización" produce lo mismo.

Las sesiones duran una hora, así que un usuario recién dado de baja conserva acceso
total a todas las organizaciones durante ese tiempo.

## Contexto adicional

El filtrado automático se salta cuando no hay contexto activo, y el resolutor devuelve
"sin organización" para quien no pertenece a ninguna. Es el comportamiento por defecto
de la librería de multi-tenencia, no una línea olvidada en un controlador.

**El motor de reportes ya implementa la guarda correcta** —sin organización, resultado
vacío—; ningún otro lugar lo hace. Ese es el patrón a replicar.

Las altas sí fallan (422), porque la validación de pertenencia compara contra una
organización nula. Las lecturas y modificaciones de registros existentes están abiertas.

## Severidad

**Crítica.** Es acceso de lectura y escritura a datos clínicos identificables de
menores pertenecientes a otros clientes. Bloquea cualquier puesta en producción
multi-organización.
