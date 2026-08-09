---
name: payment-ui-strings
description: Literal Spanish UI strings + field/permission map of the Payments flow (list, register, submit, actions, detail) — verified 2026-08-05 for end-user documentation
metadata:
  type: project
---

# Payment flow — literal UI strings & contracts (verified 2026-08-05)

Captured while producing the end-user manual for the PAYMENTS flow. Every string below was
read from disk on 2026-08-05; treat as point-in-time and re-verify before quoting again.

## Screens / files
- `frontend/src/modules/payment/PaymentListPage.tsx` — list. Title "Pagos", desc "Pagos registrados y pendientes de validación".
  Tabs: "Todos" / "Pendientes de validación" (tab pins `payment_status_id = PENDING_VALIDATION`).
  Toolbar: "Descargar" (`report.payment`, `onReport('Payment','xlsx','pagos')`), "Importar pagos" (`create.data_import` → `/import`), "Registrar pago" (`create.payment`).
  Columns: Folio · Unidad · Monto · Método · Fecha · Estado · (acciones si `update.payment`).
  Search placeholder "Buscar por folio o referencia…" (backend `searchableBy` = receipt_number, reference, physical_receipt_number).
  Filters panel (`PaymentFiltersPanel`, all lazy catalogs): "Monto ($)", "Fecha de pago", "Estado", "Método de pago", "Unidad", "Cuenta destino".
- `components/RegisterPaymentSheet.tsx` — title "Registrar pago"; submit "Registrar pago" / in direct mode "Registrar cobro"; busy "Registrando…".
  Fields: Unidad*, [Tipo de cobro: "Cargos pendientes" | "Cobro directo (servicio eventual)"], Fecha de pago*, Monto total* (Monto* en directo), Método de pago*, Cuenta destino*, Referencia, Folio recibo físico, Comprobante (required if not cash), Aplicación a cargos ("Automática (FIFO)" | "Manual"), Notas.
  Direct mode requires prop `allowDirectCharge` AND both `create.charge` + `create.payment`; extra fields from `DirectChargeFields`: Concepto* + option "Otro (especificar motivo)" → Motivo del cobro*.
- `components/SubmitPaymentSheet.tsx` — resident. Title "Reportar un pago · {unidad}"; submit "Enviar pago"; busy "Enviando…".
  Fields: Fecha de pago*, Monto*, Método de pago*, Referencia, Comprobante (required if not cash), Notas. NO unidad picker, NO cuenta destino, NO folio físico, NO aplicación a cargos. Opened from `StatementPage` button "Reportar pago" (`create.payment_submission`).
- `components/PaymentActionButtons.tsx` — "Comprobante", "Rechazar", "Validar y aplicar", "Cancelar pago".
- `components/PaymentActionDialogs.tsx` — dialogs "Validar y aplicar pago" (no reason), "Rechazar pago" (reason required, backend `min:5`, FE only blocks empty — MISMATCH worth flagging), "Cancelar pago" (reason min 5 both sides).
- `PaymentDetailPage.tsx` — header = receipt_number (or "Pago pendiente"), "Folio físico:", Monto total, DetailFields (Método de pago / Fecha de pago / Unidad / Cuenta destino / Registrado por / Referencia), Notas, "Motivo de cancelación", `ApplicationsCard` titled "Aplicación a cargos (FIFO)" (empty: "Este pago aún no se aplicó a ningún cargo."), `DownloadsCard` "Comprobantes" with "Recibo del sistema" (media `receipt_pdf`) and "Comprobante subido" (media `receipt`).

## Backend contracts confirmed
- Routes: `POST payment/register`, `payment/submit`, `payment/{id}/validate`, `payment/{id}/reject`, `payment/{id}/cancel`. Orion `payment` resource is READ-ONLY (`only index|search|show`).
- Abilities: register→`create.payment`; submit→`create.payment_submission` + unit_user membership (end_date NULL); validate/reject/cancel→`update.payment`; delinquency report→`report.payment`.
  Roles: administrator (all), treasurer (`$collections` CRUD), president (`read/create/update/report` on `$collections` — CAN validate/reject/cancel), resident & renter (`create.payment_submission` only).
- register() always creates a CONFIRMED payment with `receipt_number` assigned immediately; submit() creates PENDING_VALIDATION with `receipt_number = null` (assigned on validate).
- reject() reuses PaymentStatus::CANCELLED + `cancellation_reason` (no REJECTED row exists).
- 422 messages (literal): "El método de pago seleccionado no está habilitado en la configuración del clúster.", "El comprobante es obligatorio para pagos que no son en efectivo.", "El clúster no tiene una cuenta bancaria configurada.", "El pago no está pendiente de validación.", "Solo se puede rechazar un pago pendiente de validación.", "El pago ya está cancelado.", "La suma de aplicaciones manuales (X) supera el monto del pago (Y).", "Elige un concepto del catálogo o escribe un motivo libre, pero no ambos."
- `collections.payment_methods_enabled` Setting gates methods, but **no frontend UI writes it** (grep in `frontend/src` returns nothing, 2026-08-05) → in practice all 4 methods are always enabled. Requirement gap.
- Notifications fired: `payment_registered_direct` (admin register → titular), `payment_registered` (resident submit → board recipients), `payment_accepted` (validate → titular), `payment_rejected` (reject → titular), plus a non-event `notifyUsers` "Saldo a favor disponible" when surplus becomes credit, and the receipt email "Recibo de pago {folio}" from `GenerateReceiptPdfJob` (channels log + resend_email). Cancel fires NO notification (gap).
