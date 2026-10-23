# El onboarding nunca se completa y deja el Dashboard inalcanzable

## Qué pasó

El único paso del onboarding lleva a la pantalla de Configuración, pero esa pantalla
está protegida por una comprobación de "onboarding completado". El paso nunca puede
cerrarse y el progreso queda en 0/1 de forma permanente. El Dashboard, protegido por
la misma comprobación, resulta inalcanzable.

## Qué esperaba

Poder completar el paso de onboarding y entrar al Dashboard.

## Pasos para reproducir

1. Registrar una organización nueva y entrar por primera vez.
2. Seguir el paso propuesto en el onboarding.
3. La navegación devuelve al onboarding sin permitir avanzar.
4. Intentar abrir el Dashboard → mismo resultado.

## Contexto adicional

Sólo esas dos rutas están sujetas a la comprobación; el resto del menú es accesible.

**Severidad: bloqueante para el primer uso.**

---

## Causa raíz confirmada

El único paso del checklist envía a una pantalla de configuración que **ya no existe**:
fue eliminada durante la poda de la plantilla, pero su dirección quedó escrita como
texto en el backend (`OnboardingChecklistService`, el paso de datos de la organización).
Al no existir, la aplicación cae a su ruta de respaldo, que devuelve al inicio, que
devuelve al onboarding. Bucle cerrado.

La pantalla que sí edita esos datos es la de **datos de la organización** (`/company/me`):
llenando ahí razón social y RFC, el checklist se completa y el producto se desbloquea.

Es un residuo de la poda que ningún compilador podía detectar, porque la dirección viaja
como cadena de texto entre backend y frontend. Es exactamente el riesgo que el plan
anticipó para este tipo de acoplamiento.
