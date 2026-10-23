# CRÍTICO — Una visita anterior al nacimiento produce un tamizaje clínicamente inválido

## Qué pasó

Al registrar una visita con fecha anterior al nacimiento del niño, el sistema la acepta.
Al aplicar el tamizaje sobre esa visita, la edad calculada sale **positiva y grande** en
lugar de negativa: un niño de once meses fue clasificado con 2,065 días (cinco años y
medio).

Consecuencia: se le asigna el grupo de edad equivocado, se le presenta el banco de
reactivos equivocado, y se le califica contra las reglas equivocadas. El resultado se
ve perfectamente normal y no hay nada que delate el error.

## Qué esperaba

Que la visita se rechace por ser anterior al nacimiento; y en todo caso, que el cálculo
se niegue a resolver una edad imposible en vez de inventar una positiva.

## Pasos para reproducir

1. Registrar un niño con fecha de nacimiento reciente.
2. Registrar una visita con fecha varios años anterior a ese nacimiento. Se acepta.
3. Crear un tamizaje sobre esa visita.
4. La edad cronológica sale positiva y grande, y el grupo asignado es el de un niño
   mucho mayor.

## Contexto adicional

La validación de la visita sólo comprueba que la fecha no sea futura; nunca la compara
contra el nacimiento del niño.

El cálculo de edad usa la diferencia entre fechas y toma su cantidad de días, pero esa
cantidad **siempre es positiva**: el signo viaja en un campo aparte que el código no
consulta. Por eso una edad negativa se convierte silenciosamente en una positiva.

La validación contra el oráculo no puede detectarlo: sus 1,836 pruebas son reales y
todas tienen fechas coherentes, así que nunca ejercita una fecha imposible.

## Severidad

**Crítica por corrección clínica.** Un error de captura plausible produce un tamizaje
inválido que aparenta ser válido. Necesita defensa en dos capas: rechazar la visita, y
que el cálculo se niegue ante una edad negativa.
