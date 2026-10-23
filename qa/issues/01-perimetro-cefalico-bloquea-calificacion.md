# Capturar el perímetro cefálico vuelve el tamizaje incalificable

## Qué pasó

Al registrar el perímetro cefálico durante la aplicación de un tamizaje, el reactivo
de exploración neurológica correspondiente pasa a mostrarse como autoderivado y pierde
su control de respuesta. La pantalla lo cuenta como contestado (3/3), pero al calificar
el servidor rechaza la operación diciendo que falta contestar ese mismo reactivo.
El tamizaje queda sin poder calificarse.

## Qué esperaba

Que capturar el perímetro cefálico calificara el reactivo automáticamente y permitiera
completar el tamizaje. Es justamente el propósito de la autoderivación.

## Pasos para reproducir

1. Crear un tamizaje para un niño de cualquier grupo de edad.
2. Capturar una medida de perímetro cefálico en la somatometría.
3. Contestar el resto de los reactivos.
4. Pulsar *Calificar*.
5. El servidor responde que faltan respuestas, señalando el reactivo que él mismo bloqueó.

Confirmado en ambos sentidos: al borrar la medida, el reactivo recupera su control y el
tamizaje califica bien.

## Contexto adicional

El reactivo se bloquea cuando hay medida capturada, de modo que el aplicador no puede
registrar una respuesta para él. La verificación de completitud previa al cálculo exige
que todos los reactivos del grupo tengan una respuesta registrada, sin exceptuar los
autoderivados. La autoderivación ocurre dentro del motor de calificación, en memoria,
después de esa verificación.

La validación del oráculo no puede detectar esto: le entrega las respuestas directamente
al motor y no atraviesa el registro de respuestas.

**Severidad: bloqueante.** Impide usar una medida clínica central del instrumento.
