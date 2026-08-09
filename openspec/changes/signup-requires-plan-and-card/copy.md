# Copy contract — signup-requires-plan-and-card

Authored with the `copywriting` skill. Language: **Mexican Spanish, "tú"**. No voseo.

## Strategy

Moving from "sin tarjeta" to "con tarjeta" removes a friction-reducer, so the copy must
not merely swap the number. Three rules govern every string below:

1. **Lead with the gain, not the ask.** The trial went from 14 to 30 days — more than
   double. That is the headline. The card is a condition, mentioned second.
2. **Kill the fear of a surprise charge in the same breath.** Every place that mentions
   the card also states that nothing is charged today and that cancelling before day 30
   costs nothing. The objection is "me van a cobrar sin avisar" — answer it inline.
3. **Never claim "sin tarjeta" anywhere.** After this change it is false, and on
   `terminos.astro` it is a contractual claim.

Banned strings after this change: `sin tarjeta`, `Sin tarjeta`, `14 días`, `14 dias`.

---

## 1. Landing — hero (`landing/src/data/site.ts`)

| Line | Before | After |
|---|---|---|
| `:146` `ctaPrimary.label` | `Empieza gratis 14 días` | `Pruébalo gratis 30 días` |
| `:149` `proofs[0].text` | `Sin tarjeta` | `Hoy no pagas nada` |
| `:308` `primary.label` | `Empieza gratis 14 días` | `Pruébalo gratis 30 días` |
| `:310` `proofs[0].text` | `Sin tarjeta` | `Hoy no pagas nada` |

*Why `Pruébalo` over `Empieza`:* "empieza gratis" invites the reader to expect no payment
step at all, and the next screen now asks for a card. "Pruébalo" promises a trial, which
is exactly what they get.

*Why `Hoy no pagas nada` over `No te cobramos hoy`:* second person keeps the whole proof
row in the reader's voice, and it is literally true rather than a promise about our
behavior.

## 2. Landing — pricing (`landing/src/data/site.ts:419`)

Before: `Precios en MXN con IVA incluido. Prueba gratis 14 días, sin tarjeta.`

After: `Precios en MXN con IVA incluido. Pruébalo 30 días gratis; el primer cargo llega hasta el día 31 y puedes cancelar antes sin pagar nada.`

## 3. Landing — CTA note (`Pricing.astro:141`, `:283`, `:630`)

The same string lives in three places, one inside a client `<script>`. **All three must
change** or the text reverts to the old wording when the page hydrates.

Before: `14 días gratis · Sin tarjeta · Cancela cuando quieras`

After: `30 días gratis · Hoy no pagas nada · Cancela cuando quieras`

*Note:* the three-part rhythm is preserved so the layout does not shift.

## 4. Landing — structured data (`BaseHead.astro:85`)

Before: `Prueba gratuita de 14 días, sin tarjeta.`

After: `Prueba gratuita de 30 días. Se registra una tarjeta al contratar y el primer cargo se realiza al terminar la prueba.`

*Why the flatter register:* this string is JSON-LD consumed by search engines and shown
in results. It is a factual description, not a pitch, and an inaccurate one is a
misrepresentation risk.

## 5. Landing — terms (`terminos.astro:60-61`)

Before: `El acceso se ofrece mediante planes de suscripción. La prueba gratuita no requiere tarjeta. Los cargos, periodos y condiciones de cancelación se informan al contratar tu plan.`

After: `El acceso se ofrece mediante planes de suscripción. Para iniciar la prueba gratuita de 30 días es necesario elegir un plan y registrar un medio de pago; no se realiza ningún cargo durante la prueba. Al terminar, se cobra automáticamente el plan contratado, salvo que canceles antes. Los cargos, periodos y condiciones de cancelación se informan al contratar tu plan.`

*This is the highest-risk string in the change.* It states a contractual condition, so it
spells out the mechanism plainly: card required, no charge during the trial, automatic
charge at the end, cancellable.

## 6. App — signup (`frontend/src/components/signup-form.tsx`)

| Line | Before | After |
|---|---|---|
| `:85` `subtext` | `Crea tu cuenta y da de alta tu condominio en minutos. 14 días de prueba, sin tarjeta.` | `Crea tu cuenta y da de alta tu condominio en minutos. Pruébalo 30 días gratis.` |
| `:99` lede | `Sin compromiso. Eliges tu plan en el siguiente paso.` | `En el siguiente paso eliges tu plan. Hoy no pagas nada.` |

*Why `:99` changed even though it was not "wrong":* it said "eliges tu plan en el
siguiente paso" as a soft promise that only came true when the user arrived with a
`?plan=` parameter. Now it is always true, and it doubles as the warning that a payment
step is coming — so the card screen is not a surprise.

Legal links at `:240` and `:244` currently point to `href='#'`. They must resolve to the
real public pages, opening in a new tab so the signup form is not lost:

- Términos → `https://kivo7.com/terminos`
- Aviso de privacidad → `https://kivo7.com/privacidad`

## 7. App — super-admin provisioning labels (`frontend/src/modules/tenant/schema.ts`)

| Line | Before | After |
|---|---|---|
| `:59` (comment) | `prueba de 14 días del paquete base (sin cobro).` | `prueba de 30 días del paquete base (sin cobro).` |
| `:73` `label` | `Prueba de 14 días` | `Prueba de 30 días` |
| `:75` `description` | `…activo durante 14 días sin costo. Al vencer, el dueño deberá…` | `…activo durante 30 días sin costo. Al vencer, el dueño deberá…` |

*Scope note:* this is the super-admin courtesy trial, which stays local and genuinely
requires no card. Its copy must NOT inherit the "registra una tarjeta" wording.

---

## 8. Blocked-owner screens — three distinct states

Today the blocked screen reads the same regardless of why. After this change there are
three causes and they must not sound alike: the emotional distance between "todavía no
empiezas" and "te acabamos de cortar el servicio porque tu tarjeta falló" is enormous, and
using one message for both reads as either alarmist or negligent.

### State (a) — Never chose a plan

- **Title:** `Elige tu plan para empezar`
- **Body:** `Tu condominio ya está creado. Elige un plan para activarlo y llevarte 30 días gratis; hoy no pagas nada.`
- **Primary action:** `Ver planes`

### State (b) — Trial ended without contracting

- **Title:** `Tu prueba terminó`
- **Body:** `Se acabaron tus 30 días gratis. Elige un plan para recuperar el acceso — tu información sigue completa y no se perdió nada.`
- **Primary action:** `Elegir plan`

*The reassurance about data is deliberate:* the strongest fear at this moment is "perdí lo
que capturé". Answering it is worth more than any persuasion.

### State (c) — Charge failed (NEW — this state does not exist today)

- **Title:** `No pudimos procesar tu pago`
- **Body:** `Tu banco rechazó el cargo, así que el acceso de tu condominio está en pausa. Actualiza tu tarjeta y se reactiva en cuanto el pago se confirme.`
- **Primary action:** `Actualizar mi tarjeta`
- **Secondary:** `Cambiar de plan`
- **Foot note:** `¿Tu tarjeta está bien y aun así falla? Escríbenos a hola@kivo7.com.`

**Copy rules for state (c):**
- Do not blame the user ("tu tarjeta fue rechazada" reads as an accusation). Naming the
  bank as the actor is accurate and removes the sting.
- Do not say "error" or "problema" — say what happened and what fixes it.
- "En pausa" over "bloqueado" / "suspendido": it is reversible and the copy should feel
  reversible, because it is.
- State the reactivation condition explicitly, so nobody wonders whether they must also
  contact support after paying.
- The escape hatch matters: a customer whose card is genuinely fine and still fails has
  nowhere to go otherwise.

### Non-owner member, any blocked state

Existing `MemberBlockedView` — members can do nothing about billing, so do not send them
to plan selection.

- **Title:** `El acceso está en pausa`
- **Body:** `El administrador de tu condominio necesita actualizar la suscripción. En cuanto lo haga, vuelves a entrar con normalidad.`

---

## 9. Success screen after checkout (`SelectPlanPage.tsx` `SuccessView`)

Currently says the payment succeeded. With a trial **no money moved**, so that is wrong.

- **Title:** `Listo, tu prueba empezó`
- **Body:** `Tienes 30 días gratis con todo incluido. Tu primer cargo será el {fecha}; puedes cancelar antes desde Configuración y no se te cobra nada.`

Naming the exact date is the single most trust-building string in the flow: it converts
"me van a cobrar en algún momento" into a fact the customer controls.

---

## Verification

After implementing, this must return nothing:

```bash
grep -rn "sin tarjeta\|Sin tarjeta\|14 días\|14 dias" landing/src frontend/src
```
