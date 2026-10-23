# Hallazgos menores del barrido de QA

Agrupados por tipo. Ninguno bloquea, pero varios afectan la confianza del usuario.

## Confunden o mienten al usuario

- **Las listas que fallan muestran el estado vacío normal** ("Intenta ajustar tus
  filtros") en vez de un error. El usuario cree que no hay datos cuando en realidad
  la consulta falló.
- **El gráfico de situación en indicadores imprime claves internas**
  (`first_time_green`, `first_time_red`) en vez de los nombres del catálogo, que
  existen y sí se muestran bien en el listado nominal.
- **Los hallazgos del cálculo filtran nombres de columna al clínico**:
  `head_circumference_mm`, `gestational_age_weeks`, `birth_weight_grams`,
  `mother_age_at_birth_years`.
- **Un cuidador recién creado aparece como identificador crudo** en el selector, en
  vez de su nombre.
- **Mensajes de validación sin humanizar**: "El campo practitioner id es obligatorio".
- **La hoja de asistencia guarda sola pero no confirma nada** visualmente.

## Comportamiento

- **Pulsar Escape con un desplegable abierto cierra el formulario completo** y se
  pierde lo cargado. Es sistemático y muy fácil de disparar.
- **Los contadores por área no cuentan los reactivos alternos**: muestran 3/3 con seis
  reactivos en pantalla.
- **La petición de catálogos se dispara tres veces idénticas** en varias pantallas.

## Técnicos

- Advertencia de React en cada reactivo del tamizaje: un grupo de opciones pasa de no
  controlado a controlado (entre 23 y 35 por tamizaje).
- La mensajería push falla en consola en cada carga cuando no está configurada, en vez
  de degradar en silencio.

## Accesibilidad

- Los selectores de búsqueda no tienen nombre accesible: niño en seguimiento, niño y
  profesional en sesión, fecha y estatus de pago en la hoja de asistencia.

## Falsos positivos, para que nadie los persiga

- El botón "Completar" **sí funciona**: abre un diálogo de confirmación correctamente.
- Los grupos de edad duplicados en base **son correctos**: son las dos versiones
  legítimas del instrumento.
