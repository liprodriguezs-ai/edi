---
name: terminologia-condominio-no-cluster
description: 2026-08-08 — "clúster" quedó prohibido en todo el manual; la app también cambió sus strings a "condominio"
metadata:
  type: project
---

# "Condominio", nunca "clúster"

**2026-08-08.** Se barrió la palabra **clúster** de todo el manual público: 10
ocurrencias en 7 páginas, reemplazadas por **condominio** (plural: *condominios*).
Ambos son masculinos, así que artículos y adjetivos no cambian. `grep -rn -i
"cl[uú]ster" src/content/docs` debe dar **0** de ahora en adelante.

**Why:** el cambio de terminología es de producto, no de estilo. La landing lo
empezó (commit `bf871ee`, hero/CTA de `src/data/site.ts`) y en paralelo se
cambiaron los strings reales de la app, así que las citas textuales del manual
siguen siendo fieles. La `DOCS-STYLE-GUIDE.md` ya lo pedía desde antes: su tabla
de vocabulario (§ "usar / evitar") lista `tenant`, clúster en la columna de
**evitar** y condominio, fraccionamiento en la de usar. El manual estaba
desalineado con su propia guía en estos 10 puntos.

**How to apply:** al escribir cualquier página nueva, "condominio" es la única
palabra para el cliente que administra. "Clúster" solo sobrevive como
identificador interno (nombres de código), que el manual no cita nunca.

## Strings de la app que respaldaban esas citas

Cinco de las diez ocurrencias eran **citas literales** de mensajes del producto.
Sus fuentes (cambiadas en paralelo por otros agentes ese mismo día):

| Cita del manual | Fuente en código |
|---|---|
| «¡Configuración completa! Tu condominio está listo para operar.» | `frontend/src/modules/onboarding/OnboardingPage.tsx` |
| «Tu condominio tiene N unidades activas y el plan X admite hasta M.» | `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` |
| «Ya existe una Casa 23 en este condominio.» | `backend/Modules/Housing/.../Concerns/GuardsUnitIdentity.php` |
| «El condominio no tiene una cuenta bancaria configurada» | `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` |
| «El saldo de la cuenta bajó a $X. Revisa las finanzas del condominio.» | `backend/Modules/Treasury/app/Services/ExpenseService.php` |
| «Vacío usa el del condominio (N)» | `frontend/src/modules/chargePolicy/components/PolicyConfigForm.tsx` |
| Aviso de responsabilidad de paquetería (texto por omisión) | `backend/database/seeders/TenantCatalogSeeder.php` |

Si alguna de esas fuentes se revierte a "clúster", la cita del manual queda
mintiendo. Ver [[mapa-pagina-codigo]].

## Detalle operativo

- Reemplazo mecánico 1:1; ninguna frase necesitó reescritura de fondo. Sí hubo
  que **re-envolver a ~80 columnas** cuatro líneas que se pasaron porque
  "condominio" es más larga que "clúster" (`paqueteria`, `limite-de-unidades`,
  `cambiar-de-plan`, `configuracion-inicial`).
- Verificado: `pnpm build` con 0 errores (115 páginas), 74 enlaces del registro
  app→manual sin rotos, 0 duplicados `.md`+`.mdx`, landing intacta
  (`grep -c -- '--sl-color' dist/index.html` = 0).
