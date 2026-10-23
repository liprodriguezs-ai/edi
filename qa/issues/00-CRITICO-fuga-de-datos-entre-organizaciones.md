# CRÍTICO — Una organización puede leer los datos legales de todas las demás

## Qué pasó

Cualquier usuario con permiso de lectura de empresas —que incluye a los administradores
de una organización— obtiene, al consultar el listado de empresas, **los registros de
todas las organizaciones del sistema**, no sólo los de la suya.

Verificado en vivo: un usuario perteneciente a una única organización recibió las tres
existentes, cada una de un tenant distinto.

Lo expuesto es la identidad legal y fiscal ajena: razón social, RFC, domicilio fiscal,
representante legal, registro patronal y datos del acta constitutiva.

## Qué esperaba

Ver únicamente la empresa de la propia organización. El aislamiento entre
organizaciones es la garantía central del sistema.

## Pasos para reproducir

1. Tener al menos dos organizaciones registradas.
2. Entrar con un usuario administrador de una de ellas.
3. Consultar el listado de empresas.
4. Aparecen también las empresas de las otras organizaciones.

## Contexto adicional

La tabla de empresas sí tiene la columna que identifica a la organización, pero el
modelo no aplica el filtrado automático por organización que usa el resto del sistema,
y su controlador no restringe la consulta. El único punto que sí filtra es la acción
que devuelve "mi empresa", y las operaciones estándar de listado, búsqueda y detalle
no pasan por ahí.

La columna que identifica a la organización además es asignable en masa, así que un rol
con permiso de creación podría insertar un registro dentro de otra organización.

Es un defecto **heredado de la plantilla** de la que se derivó el proyecto, no
introducido por los módulos de dominio nuevos. Los 41 modelos creados para el dominio
EDI sí aplican correctamente el aislamiento.

## Severidad

**Crítica.** Es exposición de datos de terceros entre clientes del mismo sistema.
Debe corregirse antes de que exista más de una organización en un entorno real.
