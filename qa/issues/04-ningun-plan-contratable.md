# Una organización nueva queda atrapada sin poder contratar ningún plan

## Qué pasó

Tras registrar una organización, la aplicación exige elegir un plan y redirige toda
navegación a esa pantalla. Ningún plan es contratable: todos aparecen como
"Temporalmente no disponible" con el botón deshabilitado. No hay salida.

## Qué esperaba

Poder contratar un plan, o al menos entrar con alguna modalidad de prueba.

## Pasos para reproducir

1. Registrar una organización nueva.
2. Completar el registro hasta llegar a la selección de plan.
3. Ningún plan permite continuar; cualquier otra ruta redirige de vuelta.

## Contexto adicional

Los precios de módulo del catálogo de planes no tienen asociado su identificador en la
pasarela de pagos, y sin él cada plan se considera no contratable. Existe un comando
para sincronizar el catálogo con la pasarela, que no se ha ejecutado en este entorno.

Como rodeo, un super admin puede activar una cortesía para la organización.

**Severidad: bloqueante para el alta.**
