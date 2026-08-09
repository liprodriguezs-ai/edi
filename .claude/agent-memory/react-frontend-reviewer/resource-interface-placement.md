---
name: resource-interface-placement
description: Payload interfaces for custom HTTP actions (not entity shapes) belong in the resource file, not schema.ts
metadata:
  type: pattern
---

`schema.ts` = entity interfaces + Zod form schema + FormValues type. Nothing more (CLAUDE.md anti-patterns). Payload interfaces specific to custom actions (`RegisterPaymentPayload`, `SubmitPaymentPayload`, `GeneralConfig`, `OperationalConfig`) live in the resource file. This is intentional — they represent HTTP request shapes, not entity shapes or form shapes. Exporting them from the resource file is correct.

**Why:** Confirmed pattern from PaymentResource.ts and ConfigurationResource.ts in session 2026-06-12.
**How to apply:** Do NOT flag these as violations. Only flag IXxxResponse wrappers that duplicate the entity shape.
