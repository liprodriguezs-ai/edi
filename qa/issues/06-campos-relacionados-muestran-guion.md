# Varias pantallas muestran los campos relacionados como "—"

## Qué pasó

En el detalle del niño, el sexo y el centro de salud aparecen como "—". El mismo
patrón se repite en la lista de profesionales (profesional y centro vacíos, con el
botón quedando como "Editar —"), en la lista de visitas (profesional), en la lista de
centros (municipio) y en el detalle de un grupo de estimulación (tipo, centro y
promotora).

Los datos existen: la lista de niños sí los muestra correctamente.

## Qué esperaba

Ver el nombre del sexo, del centro, del profesional y del municipio en todas las
pantallas donde se listan o detallan.

## Pasos para reproducir

1. Crear un niño con sexo y centro de salud.
2. Abrir su detalle → ambos campos muestran "—".
3. Repetir en la lista de profesionales, de visitas y de centros.

## Contexto adicional

Los controladores de niño, centro de salud, profesional y estimulación declaran sus
relaciones como cargables **a petición**, de modo que sólo llegan si el cliente las
pide explícitamente. Los módulos construidos más tarde —tamizaje, seguimiento y
reportes— declaran además que esas relaciones se carguen siempre, y por eso sus
pantallas sí muestran los nombres.

La corrección es la misma en todos: declarar como carga permanente las relaciones que
la interfaz necesita para etiquetar.

**Severidad: grave.** No pierde datos, pero deja pantallas ilegibles.
