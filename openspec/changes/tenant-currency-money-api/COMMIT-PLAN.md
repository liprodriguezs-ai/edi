# Commit Plan — tenant-currency-money-api

Todo el cambio está SIN commitear (decisión del usuario: firma 1Password no disponible en la sesión bg).
Estado verificado: judgment-day APPROVED (0 confirmados) + sdd-verify PASS (20/20 requisitos, 0 CRITICAL).
Backend 1547 passed (10 fallas pre-existentes ajenas, ver verify-report §fallas) · Frontend 457/457 + build limpio · Pint limpio.

> Orden recomendado: commits 1→7 en `backend/`, 8→10 en `frontend/`, 11 en la raíz.
> ⚠️ NO pushear a `main` directamente si el flujo del repo lo prohíbe — decidir rama antes.

## backend/ (7 commits)

1. `feat(core): add tenant-aware money cast and serialization support`
   config/money.php · core/src/Casts/TenantMoneyCast.php · core/src/Support/TenantMoney.php ·
   app/Casts/MoneyCast.php (BORRADO) · Modules/Collections/app/Models/{Charge,Payment,PaymentApplication,CreditBalance,ChargePolicy}.php ·
   Modules/Treasury/app/Models/{BankAccount,Expense}.php · Modules/Providers/app/Models/Provider.php ·
   Modules/Projects/app/Models/{ProjectContribution,CommunityProject}.php · Modules/Collaborator/app/Models/Collaborator.php ·
   tests/Feature/Core/TenantMoneyCastTest.php

2. `feat(collections): serialize statement and delinquency money fields as Money objects`
   Modules/Collections/app/Http/Controllers/PaymentController.php · Modules/Collections/tests/Feature/StatementEndpointTest.php ·
   tests/Feature/Collections/CollectionsBatch3Test.php
   ⚠️ tests/Feature/E2E/ClusterLifecycleTest.php pertenece LÓGICAMENTE aquí pero es archivo MIXTO — ver commit 6.

3. `feat(collections): serialize charge-batch summary money fields as Money objects`
   Modules/Collections/app/Http/Controllers/ChargeBatchController.php · Modules/Collections/tests/Feature/ChargeBatchSummaryTest.php

4. `feat(treasury): serialize dashboard and public-summary money fields as Money objects`
   Modules/Treasury/app/Services/TreasuryReportService.php · Modules/Treasury/app/Http/Controllers/{PublicSummaryController,TreasuryReportController}.php ·
   tests/Feature/Treasury/{TreasuryReportControllerTest,TreasuryHttpTest}.php

5. `feat: serialize aging, funding, contribution and reports JSON money fields as Money objects`
   Modules/Collections/app/Services/CollectionsReportService.php · tests/Feature/Collections/CollectionsReportControllerTest.php ·
   Modules/Projects/app/Services/ProjectsReportService.php · tests/Feature/Projects/{ProjectsReportControllerTest,ProjectsModuleTest,ProjectsHttpTest}.php ·
   Modules/Projects/app/Http/Controllers/CommunityProjectController.php · Modules/Reports/app/Http/Controllers/ReportController.php ·
   tests/Feature/Reports/ReportsModuleTest.php
   ⚠️ Modules/Projects/app/Services/ContributionService.php tiene hunks de ESTE slice (rename getSummary) Y del 6 (formatMoney).
   Pragmático: incluirlo completo aquí (ambos hunks son de este cambio); purista: `git add -p`.

6. `refactor: centralize money formatting through TenantMoney::format()`
   Modules/Reports/app/Services/ReportFormatter.php · Modules/Collections/app/Services/PaymentService.php ·
   Modules/Treasury/app/Services/ExpenseService.php · Modules/Treasury/app/Console/Commands/SendMonthlyFinancialSummaryCommand.php ·
   Modules/Collections/app/Console/Commands/SendChargeRemindersCommand.php · Modules/Collections/resources/views/pdf/receipt.blade.php
   (+ ContributionService.php si no entró completo en el 5)
   ⚠️ tests/Feature/E2E/ClusterLifecycleTest.php — ARCHIVO MIXTO con WIP de Announcements de OTRA sesión:
   `git add -p tests/Feature/E2E/ClusterLifecycleTest.php` y tomar SOLO los 4 hunks de dinero
   (asserts de statement ~455-459 + 3 hunks de reports/contribution); dejar los hunks de channel_sms/announcements fuera.
   Puede ir en este commit o en uno propio `test(e2e): migrate money assertions to Money-object shape`.

7. `feat(configuration): validate tenant currency against curated list`
   Modules/Configuration/app/Http/Controllers/ConfigurationController.php · tests/Feature/Configuration/SubscriptionSelfServiceTest.php

## frontend/ (3 commits)

8. `feat(shared): add active-currency module and wire session sync`
   src/shared/utils/currency.ts (NUEVO) · src/shared/auth/{Session,sessionStore}.ts ·
   src/shared/utils/{money,currencyHelper}.ts · src/shared/utils/money.test.ts

9. `feat: migrate money consumers to IMoney and active-currency formatting`
   src/modules/payment/{schema.ts,StatementPage.tsx,DelinquencyPage.tsx} · src/modules/payment/components/{RegisterPaymentSheet,SubmitPaymentSheet,UnitDebtSummary}.tsx ·
   src/modules/payment/services/PaymentResource.ts · src/modules/chargeBatch/{services/ChargeBatchResource.ts,hooks/useChargeBatchStats.ts,ChargeBatchDetail.tsx} ·
   src/modules/dashboards/{resultados,tesoreria,gastos,cobranza,proyectos}/** (20 archivos: schema/Resource/Dashboard/__tests__) ·
   src/modules/expense/services/ExpenseResource.ts · src/modules/treasurerPanel/components/TreasurerHome.tsx ·
   src/modules/communityProject/{schema.ts,CommunityProjectDetailPage.tsx} · src/modules/reports/services/ReportsResource.ts

10. `feat(configuration): enable tenant currency selection with confirmation`
    src/modules/configuration/components/OperationalConfigSection.tsx

## raíz (1 commit + pendiente)

11. `docs(openspec): add tenant-currency-money-api change artifacts`
    openspec/changes/tenant-currency-money-api/ (proposal, spec, design, tasks, verify-report, este plan)
    Después: bump de pointers de submódulos backend+frontend (`git add backend frontend`) en el commit que corresponda.

⚠️ `.agents/skills/laravel-backend-architecture/SKILL.md` (regla 17) está GITIGNOREADO — vive en disco y en engram/memoria, pero NO es commiteable.
   Decisión pendiente del usuario: si se quiere versionada, moverla a un archivo trackeado (p. ej. .project-context/conventions.md).

## Exclusiones (WIP pre-existente de otras sesiones — NO tocar en estos commits)
backend: Modules/Announcements/**, app/Services/RichTextSanitizer.php, Modules/Email/resources/views/announcement-published.blade.php,
tests/Feature/Announcements/**, tests/Feature/Notifications/NotificationChannelTest.php, Modules/Collections/app/Services/TargetResolver.php, .claude/worktrees/
frontend: src/App.tsx, src/modules/announcement/**, src/modules/chargeBatch/ChargeBatchFormPage.tsx, components/BatchPreview.tsx (borrado),
src/modules/chargePolicy/ChargeRuleFormPage.tsx, src/shared/components/target/**, src/shared/utils/targetSelector.ts,
emails/announcement-published.tsx, src/shared/components/richtext/**
