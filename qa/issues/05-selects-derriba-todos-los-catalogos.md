# Un catálogo inválido vacía todos los desplegables de la pantalla

## Qué pasó

Varias pantallas muestran sus desplegables sin opciones. La petición que resuelve los
catálogos falla con error del servidor y, como el frontend pide todos los catálogos de
una pantalla en una sola petición, una clave inválida deja sin opciones a todas las demás.

Consecuencias observadas:
- Alta de visita: "Centro de salud" y "Profesional" vacíos, ambos obligatorios →
  **no se puede crear una visita desde la interfaz**.
- Alta de sesión de estimulación: ocho desplegables vacíos; al guardar reclama un
  profesional que no se puede elegir.

## Qué esperaba

Que cada desplegable cargue sus opciones, y que un catálogo mal configurado no afecte
a los demás.

## Pasos para reproducir

1. Abrir el detalle de un niño y elegir crear una visita.
2. Los desplegables de centro de salud y profesional aparecen sin opciones.
3. Repetir en el alta de una sesión de estimulación.

## Contexto adicional

El resolutor de catálogos construye la consulta sin declarar qué relaciones se pueden
cargar, de modo que cualquier catálogo que pida una relación falla. El fallo es del
lote completo, no de la clave que lo provocó.

**Severidad: bloqueante.** Impide crear visitas, que son el punto de partida del tamizaje.

---

## Causa raíz confirmada

La petición pide, junto al catálogo de profesionales, una relación que **ese recurso no
declara**. El servidor responde con un error de relación desconocida.

Lo que lo vuelve grave es que la petición es **por lotes**: una sola relación mal pedida
tumba la respuesta entera, así que **todos** los catálogos del formulario quedan vacíos.
Medido: 2 desplegables en "Nueva visita" y **8** en "Nueva sesión de estimulación".

Consecuencia operativa: **no se puede crear ninguna sesión de estimulación desde la
interfaz**, ni ninguna visita. El barrido tuvo que crear visitas por API para poder
seguir probando.

Dos correcciones, ambas necesarias:
1. Dejar de pedir esa relación (o declararla donde corresponde).
2. Que un catálogo fallido no arrastre a los demás — hoy el lote es todo o nada.
