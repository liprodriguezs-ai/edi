# Catálogo de defectos — EDI

> **Tercera ronda (2026-08-13): 4 defectos nuevos, encontrados y corregidos.**
> Suites: 850 backend · 293 frontend · typecheck limpio.
>
> 1. **El CRUD de reportes programados respondía 403 a todo**: sus permisos nunca
>    se sembraron (faltaban las filas en `modules.csv`, `permissions.csv` y
>    `module_system.csv`). Una ruta gateada por un permiso inexistente parece una
>    política de permisos, no una fila de seed olvidada.
> 2. **El detalle del tamizaje crasheaba**: puse el `useCallback` del botón de
>    descarga DESPUÉS de los returns tempranos → *"Rendered more hooks than during
>    the previous render"*. Pasó typecheck y las 292 pruebas porque **ninguna
>    renderizaba esa pantalla en la transición carga→cargado**; ya hay prueba.
> 3. **Indicadores mostraba claves crudas** (`first_time_green`) mientras el
>    listado nominal de al lado sí traducía.
> 4. **Migas de pan sin traducir** ("Visit", "Child"): el mapa seguía lleno de
>    rutas del dominio amputado y le faltaban todas las de EDI.

> **Segunda ronda (2026-08-12, navegador + API sobre la app corriendo): 16 correcciones
> confirmadas end-to-end, y 2 defectos nuevos encontrados y corregidos.**
>
> Confirmado en la app real: onboarding sin bucle · listas con datos (5 tamizajes,
> 4 seguimientos) · "Profesional" con nombre · sexo y centro en el detalle · clic en
> filas navegando · modal a 36% del ancho en escritorio y sin desbordar en móvil ·
> "Nueva ocupación" · catálogo crudo del instrumento aún cerrado (403).
>
> **Nuevos, ambos por la misma causa —un fallo que respondía "bien" y no mostraba nada:**
> 1. El catálogo de profesionales llegaba VACÍO: el parser exige una anotación
>    `@Relation` en el docblock y la rechazaba pese a estar en la lista permitida.
>    Mi propia resiliencia lo degradaba a lista vacía y lo ocultaba.
> 2. La lista de seguimientos respondía 422 ("due_date no está en la lista blanca")
>    y renderizaba cero filas sin avisar: el controlador nunca declaró `sortableBy()`,
>    cuyo valor por defecto en Orion es lista vacía.

> **Estado (2026-08-12): 16 corregidos y verificados.** Cada corrección se vio fallar
> sin el parche antes de darla por buena. Suites en verde: **838 backend, 292 frontend**,
> typecheck limpio, y el oráculo clínico intacto en 99.89%.
>
> Corregidos: aislamiento sin organización · ficha de organización cruzada · edad
> negativa · visita anterior al nacimiento · perímetro cefálico · listados vacíos ·
> catálogos de formularios · onboarding en bucle · fechas en horario universal ·
> clic en filas (escritorio) · mensajes de validación en clave cruda · modales al 100% ·
> campos en "—" · pantalla en blanco · recalificar inalcanzable · concordancia de
> género, terminología de condominios y mensajes clínicos en inglés.
>
> **NO son defectos** (ver abajo): Inicio vacío y el catálogo de materiales/canciones.
>
> **Fuera de alcance de una corrección** (ver abajo): la pantalla de suscripción y las
> categorías de notificación heredadas.


Resultado de tres barridos independientes sobre el sistema construido: interfaz
(47 rutas, dos cuentas), API y seguridad, y auditoría estática de contratos. Cada
hallazgo listado abajo fue **verificado de forma independiente** antes de entrar aquí.

No se reportó nada a GitHub. Este catálogo es la entrada de trabajo para la corrección.

---

## Bloqueantes

| # | Defecto | Documento |
|---|---|---|
| 1 | **Un usuario sin organización lee y modifica los datos clínicos de todas** | [aislamiento falla abierto](00-CRITICO-aislamiento-falla-abierto.md) |
| 2 | **Las organizaciones se ven y se pisan la ficha entre sí** | [fuga entre organizaciones](00-CRITICO-fuga-de-datos-entre-organizaciones.md) |
| 3 | **Una visita anterior al nacimiento produce un tamizaje inválido con cara de válido** | [edad negativa](01b-CRITICO-edad-negativa-se-vuelve-positiva.md) |
| 4 | Capturar el perímetro cefálico impide calificar | [perímetro cefálico](01-perimetro-cefalico-bloquea-calificacion.md) |
| 5 | Onboarding sin salida: bucle en la primera pantalla | [onboarding](03-onboarding-sin-salida.md) |
| 6 | No hay plan contratable y la pantalla de suscripción falla | [planes](04-ningun-plan-contratable.md) |
| 7 | Un catálogo roto vacía los desplegables de todos los formularios | [selects](05-selects-derriba-todos-los-catalogos.md) |
| 8 | La asistencia deja de guardar todas las noches desde las 18:00 | [asistencia](10-asistencia-no-guarda-de-noche.md) |

Los tres primeros son los que impiden cualquier uso real: dos son fugas de datos
clínicos de menores entre clientes, y el tercero produce un resultado clínico falso.

El número 7 tiene además un efecto operativo total: **sin catálogos no se puede crear
ninguna sesión de estimulación ni ninguna visita desde la interfaz**.

## Graves

| # | Defecto | Documento |
|---|---|---|
| 9 | Los listados de tamizajes y seguimientos aparecen vacíos habiendo datos | [listas vacías](02-listas-de-tamizajes-y-seguimientos-vacias.md) |
| 10 | Nadie puede recalificar: el permiso lo tiene quien no tiene datos | [recalificar](11-recalificar-inalcanzable-por-permisos.md) |
| 11 | Inicio no muestra nada; un tamizaje repetido deja la pantalla en blanco | [inicio y blanco](12-dashboard-vacio-y-pantalla-en-blanco.md) |
| 12 | Los detalles muestran "—" donde las listas sí resuelven el dato | [campos en guion](06-campos-relacionados-muestran-guion.md) |
| 13 | Al detalle de un grupo no se llega desde su lista | [detalle de grupo](07-detalle-de-grupo-inalcanzable.md) |
| 14 | Revocar un permiso no surte efecto hasta que expira la sesión (hasta 1 hora) | [menores](08-menores-y-cosmeticos.md) |
| 15 | Errores de validación invisibles: se pulsa Crear y no pasa nada | [menores](08-menores-y-cosmeticos.md) |

## Menores y cosméticos

Ver [menores y cosméticos](08-menores-y-cosmeticos.md): claves crudas en pantalla,
fechas en horario universal, migas de pan sin traducir, concordancia de género en
botones, categorías de notificación heredadas del producto anterior, terminología de
condominios que sobrevivió a la poda, un mensaje clínico en inglés y dos avisos de
accesibilidad.

## Pendiente de revisar

[Modales al 100% de ancho](09-PENDIENTE-modales-ancho-completo.md) — señalado
directamente y aplazado a propósito para no interrumpir las pruebas.

---

## Dos que NO son defectos, y dos que no se arreglan con un parche

**Inicio vacío no es un defecto.** Es un placeholder declarado en su propio comentario
("los paneles de EDI se construyen en su propio change de SDD"), y el endpoint que el
barrido señaló como existente **devuelve una lista vacía**: también es un stub. Cablear
uno al otro no mostraría nada. Construir el tablero es funcionalidad nueva, no una
corrección.

**La pantalla de suscripción es colateral de la amputación.** Las cinco rutas que el
frontend consume (`/config/subscription*`) las servía el módulo `Configuration`, borrado
al quitar el dominio de la plantilla. El servicio que arma el payload **sobrevivió**;
solo se fue la capa HTTP, y hoy existen rutas equivalentes con otra forma
(`tenant/{id}/billing`, `billing/plan-catalog`). Restaurarla es portar cinco endpoints
y decidir el mapeo — trabajo con alcance propio, no un parche. Queda además una
referencia colgada a esas rutas en el middleware de suscripción.

**Las categorías de notificación heredadas siguen vivas.** Cobranza, Avisos, Accesos,
Chat, Proyectos y Amenidades no son constantes muertas: están **referenciadas 24 veces**
en código que sobrevivió. Quitarlas rompe esas referencias, así que es un refactor del
módulo de Notificaciones y no un cambio de etiquetas.

## Un falso positivo, para que no se corrija de más

El barrido de interfaz reportó que los catálogos de **materiales didácticos** y
**canciones** están vacíos, y lo clasificó como siembra faltante. **No lo es**: están
vacíos por decisión de diseño registrada — son catálogos de sugerencia que cada
organización llena, y la sesión de estimulación los captura como texto libre, nunca
como referencia obligatoria a esos catálogos.

Lo que sí queda en pie de ese hallazgo es lo de al lado: hoy esos desplegables no se
pueden llenar desde la interfaz por el defecto número 7.

---

## Lo que sí funciona (verificado, no asumido)

Vale registrarlo porque delimita dónde **no** hace falta buscar:

- **El motor de calificación es sólido donde fue probado.** Edad corregida por
  prematurez con sustitución de grupo y aviso explícito; los límites entre los 15 grupos
  coinciden con la tabla del instrumento; fuera de rango se rechaza indicando el rango.
- **La evaluación alterna funciona**: cuando un eje sale todo amarillo, el servidor pide
  los reactivos del grupo anterior y la interfaz los inserta y permite reintentar.
- **Las cinco invariantes del tamizaje se cumplen**: un resultado enviado desde el
  cliente se ignora, un rechazo no persiste nada a medias, un tamizaje completado se
  congela, y recalificar siempre deja constancia.
- **Con organización activa, el aislamiento es correcto** en los 34 modelos: todo acceso
  cruzado responde "no encontrado", la inyección de claves ajenas se bloquea y la
  suplantación de organización por cabecera se rechaza.
- El ciclo completo del seguimiento, la prueba confirmatoria, el listado nominal con
  exportación, el CRUD de catálogos con sus validaciones, y el inspector del instrumento.

---

## Por qué las suites automatizadas no vieron casi nada de esto

Vale más que la lista misma, porque señala dónde faltan pruebas:

- **La costura entre backend y frontend no se prueba.** Las pruebas de backend ejercitan
  los controladores directamente y las de frontend simulan la API, así que ninguna
  atraviesa el contrato real. Ahí viven los defectos 7, 9, 11 y 12.
- **La validación contra el oráculo es ciega ante el mundo sucio.** Sus 1,836 pruebas son
  reales y todas tienen fechas coherentes y datos completos: nunca ejercita una fecha
  imposible (defecto 3) ni un valor que bloquee la captura (defecto 4). Entrega las
  respuestas directo al motor y jamás toca la persistencia.
- **No hay ninguna prueba de aislamiento para el caso sin organización**, que es
  justamente el que falla abierto (defecto 1).

## Faltante de entorno

No existe un seeder de datos de desarrollo: la aplicación arranca sin ninguna
organización y no puede verse funcionando sin dar de alta todo a mano. El de la
plantilla se eliminó al amputar su dominio y no se reemplazó.

---

## Origen de cada hallazgo

Tres barridos deliberadamente descoordinados, para que no compartieran puntos ciegos:

1. **Interfaz** — 47 rutas con dos cuentas, contrastando cada pantalla contra la base.
2. **API y seguridad** — aislamiento, permisos, validación e invariantes del dominio.
3. **Auditoría estática** — contratos entre backend y frontend.

Coincidieron en cuatro defectos por caminos distintos, lo que eleva la confianza en
ellos. Los que aparecieron en un solo barrido fueron reproducidos antes de entrar aquí.
