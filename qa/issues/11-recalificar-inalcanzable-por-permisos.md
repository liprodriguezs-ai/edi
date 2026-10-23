# Nadie puede recalificar un tamizaje: el permiso lo tiene quien no tiene datos

## Qué pasó

Al abrir "Recalcular" sobre un tamizaje, aparece el aviso **"Sin permiso"** y el selector
de versión del instrumento queda vacío. La operación es imposible de completar.

La pantalla necesita leer el catálogo del instrumento para ofrecer las versiones, pero
**ningún rol de organización tiene permiso de leerlo** — por decisión de diseño
deliberada, documentada en la propia siembra de permisos: el catálogo es contenido de
plataforma y el inspector es una herramienta exclusiva del administrador de plataforma.

El resultado es un candado cerrado sobre sí mismo: quien tiene el permiso (el
administrador de plataforma) no pertenece a ninguna organización y por tanto no debería
ver tamizajes; quien tiene los tamizajes no puede pedir las versiones.

## Qué esperaba

Poder recalificar un tamizaje con un rol clínico de la organización.

## Pasos para reproducir

1. Entrar como dueño de la organización.
2. Abrir un tamizaje ya calificado → "Recalcular".
3. Aparece "Sin permiso" y el selector de versión queda vacío.

## Contexto adicional

Es una contradicción entre dos piezas propias, no un permiso olvidado. El diseño dice
textualmente que el módulo de tamizaje debe servir estas lecturas **por sus propios
endpoints, nunca navegando el catálogo crudo**; la pantalla de recalificar hace
exactamente lo segundo.

La corrección coherente con el diseño es que el tamizaje exponga las versiones aplicables,
no que se le abra el catálogo a los roles de organización.

Los permisos de recalificar y de listado nominal sí están correctamente separados como
capacidades propias; el problema es sólo esta lectura.

## Severidad

**Grave.** Recalificar es la única vía para revisar un resultado con reglas corregidas, y
hoy no la puede ejecutar nadie.
