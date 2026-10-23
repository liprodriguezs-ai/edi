# Las listas de Tamizajes y Seguimientos aparecen vacías aunque haya datos

## Qué pasó

Las pantallas de Tamizajes y de Seguimientos muestran el estado vacío ("No se
encontraron resultados") aun cuando existen tamizajes completados y seguimientos
creados. El alta manual de un seguimiento tampoco funciona: su selector de tamizaje
no ofrece ninguna opción.

## Qué esperaba

Ver los tamizajes y seguimientos existentes, y poder elegir un tamizaje al crear un
seguimiento manual.

## Pasos para reproducir

1. Completar un tamizaje (queda registrado, verificable en el detalle del niño).
2. Abrir la pantalla de Tamizajes → aparece vacía.
3. Abrir la pantalla de Seguimientos → aparece vacía pese a que el tamizaje generó
   seguimientos automáticos.
4. Intentar crear un seguimiento manual → el selector de tamizaje no carga opciones.

## Contexto adicional

Los recursos de tamizaje, seguimiento y referencia exponen un conjunto de operaciones
restringido que excluye la operación de búsqueda. El listado del frontend se construye
justamente sobre esa operación, así que la petición se rechaza por método no permitido.
Los demás recursos del sistema (42) sí la exponen.

Ninguna suite lo detecta: las pruebas de backend ejercitan el controlador directamente
y las de frontend simulan la API. El defecto vive en la costura entre ambas.

**Severidad: bloqueante.** Dos pantallas centrales del producto son inutilizables.

---

## Causa raíz confirmada

La búsqueda de tamizajes y de seguimientos **no acepta el método que la aplicación usa**:
la ruta quedó registrada sin esa capacidad al declarar el recurso.

Lo que agrava el defecto es la presentación: la pantalla no muestra ningún error, muestra
**"No se encontraron resultados · Intenta ajustar tus filtros"**. Es un vacío falso —
había 5 tamizajes y 4 seguimientos en la base. Quien lo use concluirá que no hay datos, no
que el producto está roto.

Afecta también a la pantalla de aplicar tamizaje sobre una visita ya tamizada, que queda
en blanco por depender de esta misma búsqueda.
