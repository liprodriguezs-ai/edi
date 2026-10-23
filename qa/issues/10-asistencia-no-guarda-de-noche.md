# La hoja de asistencia deja de guardar todas las noches a partir de las 18:00

## Qué pasó

Al tomar asistencia de un grupo de estimulación después de las 18:00 hora local, la
casilla "Asistió" **se revierte sola y la pantalla no dice absolutamente nada**. No se
guarda nada.

La causa es la fecha: la pantalla la precarga en horario universal, que a partir de las
18:00 en México ya es el día siguiente. El servidor rechaza correctamente una fecha
futura, pero el rechazo nunca llega a la persona.

Agravante: la pantalla **no tiene botón de guardar** —guarda al marcar cada casilla—,
así que quien la usa no tiene ningún recurso al que recurrir.

## Qué esperaba

Que la fecha propuesta sea la de hoy en la zona horaria de la organización, y que un
rechazo del servidor se vea en pantalla.

## Pasos para reproducir

1. Después de las 18:00 hora de México, entrar a un grupo de estimulación.
2. "Tomar asistencia". La fecha aparece con **mañana**.
3. Marcar "Asistió" → la casilla se desmarca sola, sin mensaje.
4. Corregir la fecha al día de hoy → guarda y persiste bien.

## Contexto adicional

Mismo origen en otras dos pantallas: "Fecha de referencia" de un seguimiento y la fecha
de sesión de estimulación también se precargan en horario universal. Una referencia
quedó guardada con la fecha de mañana.

La organización tiene su zona horaria configurada; los formularios no la usan.

## Severidad

**Bloqueante en la ventana de 18:00 a medianoche, todos los días.** Es justo el horario
en que se capturan las sesiones de la tarde.
