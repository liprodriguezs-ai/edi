---
name: authz-controller-override-not-policy
description: Why per-record authorization in this Orion stack lives in controller authorize() overrides, NOT Laravel Policies/Gates
metadata:
  type: project
---

En este proyecto, `core/src/Http/Controllers/TraitController::authorize($ability, $arguments)` REEMPLAZA por completo el mecanismo de autorización de Orion/Laravel: NO invoca Gates ni Policies. Solo hace `parseAbilityAndArguments()` + `$user->tokenCan($ability)` (o wildcard `*`). Es decir, las Policies de Laravel están efectivamente bypasseadas en el stack Orion del proyecto.

**Consecuencia para reviews:** cuando un endpoint necesita autorización a nivel de REGISTRO (no solo ability de token) — ej. un residente que puede leer SOLO los pagos de su unidad — el patrón correcto y consistente es OVERRIDE de `authorize()` en el controller (chequeando `$arguments instanceof Modelo` para distinguir el path show de index/search). Recomendar "usá una Policy" sería INCORRECTO acá: el core nunca las llama.

Referencia: `PaymentController::authorize()` (Collections) hace esto para el deep-link de cobranza (resident lee su pago vía pivot `unit_user` con `end_date IS NULL`). Solo el path show pasa instancia del modelo; index/search/delinquency pasan la CLASE → mantienen el check estricto de ability vía `parent::authorize()`.

Patrón de ownership repetido en Collections (candidato a extraer): `UnitUser::where('unit_id',$x)->where('user_id',$u)->whereNull('end_date')->exists()`. Ver [[collections-slice-a-charge-policy]].
