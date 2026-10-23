---
name: clinical-record-page-sources
description: Code backing /docs/expediente/nino and /docs/expediente/prueba-edi (child record tabs, NewScreeningDialog, screening flow labels).
metadata:
  type: reference
---

- `/docs/expediente/nino`: `frontend/src/modules/child/ChildDetailPage.tsx` (tabs, header, `?tab=` values resumen/pruebas/citas/visitas/nutricion/datos), `child/components/ChildSummaryCards.tsx`, `screening/components/{NewScreeningDialog,ChildScreeningsPanel,ApplyModeFields}.tsx`.
- `/docs/expediente/prueba-edi`: `screening/utils/statusCopy.ts` (status labels + 3 steps), `screening/{ScreeningApplyPage,ScreeningDetail}.tsx`, `components/{ScreeningReview,CloseScreeningAction,ScreeningMoreActions,RescoreModal,InvalidateModal}.tsx`.
- Old labels renamed 2026-10-21: Recalcular→Verificar cálculo, Invalidar→Anular prueba; appointment type "Tamizaje EDI"→"Prueba EDI" (`CatalogSeeder`).
- Menu (2026-10-21): Agenda · Niños · Edad corregida · Seguimientos; groups Estimulación · Reportes (incl. Prueba EDI) · Configuración. Pinned by `backend/tests/Feature/System/ClinicalMenuTest.php`.
