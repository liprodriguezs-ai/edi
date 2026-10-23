# Los modales ocupan todo el ancho de la pantalla

## Qué pasó

Nueve de los dieciséis modales del producto se abren ocupando **todo el ancho de la
ventana** menos un margen de 2 rem, en cualquier tamaño de pantalla. En un monitor
normal, un formulario de tres campos se estira de borde a borde.

## Qué esperaba

Que el modal tenga un ancho legible en pantallas grandes, y que sólo use todo el ancho
en móvil.

## Causa raíz

El componente base de diálogo perdió su tope de ancho. Conserva el comportamiento móvil
—todo el ancho menos el margen— pero **le falta el límite para pantallas medianas en
adelante**, que es el que trae de fábrica la librería de componentes.

La prueba de que es un accidente y no una decisión de estilo: el componente hermano, el
diálogo de confirmación, en el mismo directorio, **sí conserva ese tope**. Se cayó en uno
de los dos.

Alcance: 16 usos, de los cuales 7 fijan su propio ancho y **9 quedan sin tope**.

## Cuidado: la corrección obvia rompe tres modales

Reponer el tope en el componente base **no basta**, y hecho a secas causa una regresión.

De los 7 modales que hoy fijan su ancho, **6 lo hacen sin distinguir tamaño de pantalla**
(tres de ancho medio, dos angostos, uno muy ancho) y sólo 1 lo hace correctamente. Como
el tope del componente base sí distingue tamaño de pantalla, de pantallas medianas en
adelante **le ganaría a esos seis**:

- el modal más ancho del producto **se encogería**;
- los dos angostos **se ensancharían**.

Es decir: se arreglan nueve y se rompen tres, en silencio y sólo en pantallas grandes.

## Corrección correcta

Dos pasos, en el mismo cambio:

1. Reponer en el componente base el tope de ancho para pantallas medianas en adelante,
   conservando intacto el comportamiento móvil.
2. Migrar los 6 modales que fijan ancho sin distinguir tamaño de pantalla, para que lo
   hagan como el séptimo, que ya está bien.

Verificar después los tres modales afectados en pantalla ancha.

## Severidad

**Cosmética pero generalizada**: afecta a más de la mitad de los modales del producto y
se nota en cada uso.
