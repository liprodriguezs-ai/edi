# Módulos

## Backend — `backend/Modules/` (nwidart)

Que una tabla sea tenant-scoped lo define `BaseModel::columns($table, true)` +
el trait `BelongsToTenant`, no la carpeta ni ninguna clave de `module.json`.

### Infraestructura — 16 módulos, existentes

| Módulo | Qué hace |
|---|---|
| `Audit` | Auditoría (owen-it/laravel-auditing) |
| `Company` | Identidad legal/fiscal/branding de la organización |
| `Country` | Catálogo de países (central) |
| `Dashboard` | Métricas y checklist de onboarding |
| `Email` | Mailables + vistas Blade generadas desde React Email |
| `Module` | Registro de módulos en BD: permission_entity, ruta, icono, visibilidad |
| `Municipality` | Catálogo de municipios (central) |
| `Notification` | Motor de notificaciones multicanal + plantillas por tenant |
| `Role` | Roles y permisos (spatie/laravel-permission) |
| `Setting` | Settings key/value por tenant |
| `SubscriptionPlan` | Planes, cuotas, bandas de facturación, Stripe/Cashier |
| `System` | Agrupadores de menú; sirve `GET /api/menu` |
| `Tenant` | Gestión de organizaciones (stancl/tenancy) |
| `Tracking` | Analytics (pixels + eventos server-side) |
| `User` | Usuarios e invitaciones |
| `UserProfile` | Perfil por tenant |
| `Clinic` | `health_center`, `practitioner_profile` — estructura organizacional (centros de salud, profesionales que aplican tamizajes) |
| `ChildRecord` | `sex`, `population_type` (catálogos centrales), `caregiver_relationship`, `education_level`, `occupation` (catálogos tenant-scoped), `child`, `caregiver`, `child_caregiver`, `birth_record`, `visit` — expediente clínico del niño. PII de menores: auditoría completa vía `audits`, nunca en logs de aplicación (REQ-XC-005) |
| `Instrument` | 13 tablas CENTRALES, todas read-only vía API (solo super admin, `only(['index','show'])`): 3 catálogos de soporte NO versionados (`result_color`, `development_area`, `application_position`) + registro (`instrument`, `instrument_version`) + 8 tablas de contenido versionado (`age_group`, `screening_item`, `screening_item_option`, `risk_factor`, `head_circumference_reference`, `area_scoring_rule`, `global_scoring_rule`, `global_result_message`). El libro de reglas del instrumento EDI: contenido inmutable una vez publicado (`InstrumentContentObserver`/`InstrumentVersionObserver`), ids UUIDv5 determinísticos, `content_hash` SHA-256 verificado por `instrument:verify`. Dos ediciones sembradas: `edi-legacy-2013` (bug-compatible con el sistema legado) y `edi-2022-2ed` (predeterminada). Inspector de solo lectura en `frontend/src/modules/instrument/` |
| `Stimulation` | **Construido (`edi-stimulation-sessions`)**: entidad concreta de dominio (NO motor de formularios genérico) para la sesión de estimulación temprana — 4 secciones oficiales (integración/preparación/actividad central/relajación) como grupos de columnas en `stimulation_session`, con `stimulation_session_movement`/`stimulation_session_relaxation_zone` como joins de sincronización declarativa (`StimulationSessionService`, sin endpoint propio). `stimulation_group` + `stimulation_group_member` (inscripción) + `stimulation_attendance` (upsert masivo idempotente por natural key `(group, child, date)`, único PLANO no parcial a propósito — D8). 3 catálogos CENTRALES (`session_section`, `stimulation_area`, `attendance_payment_status`) + 6 TENANT-SCOPED (`stimulation_dynamic`, `body_zone`, `massage_movement`, `didactic_material`, `song`, `stimulation_group_type`). Material/canción son texto libre (snapshot histórico), nunca FK a los catálogos de sugerencia. Rutas bajo `/api/stimulation_session*`, `/api/stimulation_group*`, `POST /api/stimulation_group/{id}/attendance`, 66 permisos |

### Dominio EDI — a construir

| Módulo | Posee | Por qué separado |
|---|---|---|
| `Screening` | **Backend completo (`edi-screening-application`)**: motor puro `Modules\Screening\Services\Scoring\ScoringEngine` (sin Eloquent/DB, verificado por `ScoringPurityTest`, INTOCABLE) + `CsvRuleSetLoader`/`DatabaseRuleSetLoader`. Reproduce 99.89% (1,834/1,836) del oráculo — gate de CI en `tests/Unit/OracleReplayTest.php`. Persistencia y API ya existen: 7 tablas tenant-scoped (`screening`, `screening_answer`, `screening_area_result`, `screening_risk_factor_answer`, `screening_finding`, `screening_score_run`, `confirmatory_test`) + 4 catálogos centrales (`screening_status`, `screening_situation` con `sis_code`, `finding_type`, `confirmatory_test_result`). Servicios: `RuleSetProvider` (memoizado), `ScreeningApplicationService` (draft + banco de reactivos), `ScreeningAnswerService` (batch idempotente + staleness reset), `ScreeningScoreService` (pipeline de calificación transaccional), `ScreeningLifecycleService` (complete/rescore/invalidate). Rutas bajo `/api/screening*` y `/api/confirmatory_test*`, 13 permisos (incl. `rescore.screening`) — módulos **`Tamizajes`/`Pruebas Confirmatorias` sembrados OCULTOS** (D21): sin UI en este change. **Pendiente para `edi-screening-ui`**: construir el frontend, agregar `AngularController::$mapperSelects`/`useCatalogs.ts::CatalogType` para los 4 catálogos si el UI los necesita como dropdown, y quitar `Tamizajes` de `$hidden` en `seedModuleVisibility()`. FUA en PDF (`GET /screening/{id}/document`, REQ-SCR-015) quedó fuera de este change (fase severable) — pendiente como su propio follow-up | Alto volumen, tenant-scoped, append-only. Acá vive el motor de calificación |
| `FollowUp` | **Construido (`edi-followup-and-referral`)**: `follow_up` (conducta derivada de un resultado de tamizaje: reaplicación a 3 meses, referencia a especialista, prueba confirmatoria) + `referral` (acto concreto de referencia, N por follow_up), ambas tenant-scoped. 2 catálogos CENTRALES (`follow_up_type`, `follow_up_status`) + 1 TENANT-SCOPED (`referral_specialty`, 6 nombres sembrados). Disparo por evento: `Modules\Screening\Events\ScreeningCompleted` (1 línea de dispatch en `ScreeningLifecycleService::complete()`, dentro de la transacción) → `CreateFollowUpsFromScreening` (listener SÍNCRONO, no colas — un tamizaje completado nunca existe sin su conducta): cierra-y-luego-crea (auto-cierra reaplicaciones abiertas del niño cuya visita origen sea anterior o igual a la que completa, después crea según la matriz de color). Idempotencia por índice único parcial `(screening_id, follow_up_type_id)`. `overdue` se MATERIALIZA (nunca se calcula al leer): derivación en cada escritura + comando diario `follow-up:refresh-overdue` (converge pending↔overdue por tenant, ambos sentidos). Transiciones manuales explícitas (`start`/`complete`/`cancel`) vía `FollowUpLifecycleService`; referencias vía `ReferralService` (type gate a `specialist_referral`, efecto colateral a `in_progress`, notifica `follow_up.referral_created` a administradores reusando `DispatchesNotifications::notifyEvent` — sin canal nuevo). Rutas bajo `/api/follow_up*`, `/api/referral*`, `/api/referral_specialty*`, 18 permisos. Frontend construido: `frontend/src/modules/followUp/` (lista con filtro de estado + ventana de vencimiento, detalle con transiciones y referencias, alta manual) |
| `Reports` | **Construido (`edi-reports-and-indicators`)**: tablero de indicadores agregados (sin PII) + concentrado EDI + listado nominal (PII, permiso propio). Materializa meses cerrados en `screening_indicator_snapshot` (tenant-scoped, grano `(tenant_id, health_center_id NULLABLE, period_start)`, NULL = rollup de organización, unicidad `NULLS NOT DISTINCT`); el mes en curso se calcula en vivo con la MISMA clase (`IndicatorAggregationQuery`, set-based, `GROUPING SETS` para centro+rollup en una sola query), cacheado por tenant vía `CachesTenantReports`. Comando nocturno `reports:build-indicator-snapshots {--tenant=} {--period=} {--months=3}` (idempotente, por tenant, ventana móvil de 3 meses, 02:00). Endpoints agregados `GET /api/reports/indicators/{summary,monthly-series,area-breakdown,concentrate}` tras `read.screening_indicator` (permiso DISTINTO del nominal — NUNCA requiere permiso de Screening/ChildRecord). Listado nominal como recurso Orion propio (`GET /api/reports/nominal`, `$keyForPermission='nominal_report'`, `newModelQuery()` acotado al "counted set": no invalidados, con resultado) — permiso propio, independiente de `read.screening`. Reutiliza el motor genérico de reportes de `qch/core` (`ReportController::REPORT_MODEL_MAP`, antes vacío): 3 entradas nuevas (`Screening`, `Child`, `ScreeningIndicator` → `ScreeningIndicatorSnapshot`) con `reportColumns()` en cada modelo, encabezados en español, closures que leen SOLO relaciones eager-loaded (firewall N+1). Export xlsx-only (pdf sigue devolviendo 422 por diseño del engine). Aislamiento por tenant verificado en export y en indicadores (cache key por tenant). **Diferido a `edi-anthropometry-references`**: estado nutricional (tablas de referencia OMS peso/talla no existen en `Instrument`). **Fase 6 (severable) NO entregada en este change**: `scheduled_report` — abrir `edi-scheduled-reports` |

Dependencias (acíclicas):
`Reports → {Screening, Stimulation, FollowUp} → ChildRecord → Clinic`;
`Screening → Instrument`; `FollowUp → Screening`.

## Frontend — `frontend/src/modules/`

Existentes: `audit`, `billingPackage`, `catalogs`, `checkoutSubscription`, `company`,
`dashboard`, `notification`, `onboarding`, `role`, `system`, `tenant`, `user`,
`userProfile`, `healthCenter`, `practitioner`, `child`, `caregiver`, `visit`,
`instrument`, `screening`, `stimulationSession`, `stimulationGroup`, `followUp`, `reports`.

`caregiver_relationship`, `education_level` y `occupation` no tienen módulo propio:
son 3 registros en `catalogs/pages.tsx` (`createSimpleCatalogPage`, variante sin
columna `order` de `createCatalogPage`). `caregiver`/`child_caregiver` no tienen
página de listado propia (D11/D17 del diseño de `edi-clinic-and-child-registry`):
se gestionan desde `ChildDetailPage` (tab "Cuidadores").

`instrument` es un inspector de solo lectura para super admin (`InstrumentVersionListPage`,
`InstrumentVersionDetailPage` con tabs Grupos/Reglas por eje/Reglas globales/Factores de
riesgo/Perímetro cefálico); no tiene formularios ni mutaciones — el contenido entra
exclusivamente vía `InstrumentSeeder` en el backend.

`stimulation_dynamic`, `body_zone`, `massage_movement`, `didactic_material`, `song` y
`stimulation_group_type` tampoco tienen módulo propio: son 6 registros
`createSimpleCatalogPage` en `catalogs/pages.tsx` (edi-stimulation-sessions). `stimulationGroup`
incluye `AttendanceSheetPage.tsx` (grilla niños × fecha, guardado optimista por toggle).

`referral_specialty` tampoco tiene módulo propio: es 1 registro `createSimpleCatalogPage`
en `catalogs/pages.tsx` (edi-followup-and-referral). `followUp` (`FollowUpListPage`,
`FollowUpDetailPage`) es la bandeja de trabajo de seguimientos: filtro de estado (catálogo
lazy) + ventana de vencimiento (Vencidos/Próximos 7 días/Todos), alta manual (niño → tamizaje
completado → tipo → fecha), transiciones (iniciar/completar/cancelar con motivo obligatorio)
y registro de referencias (N por seguimiento, especialidad + institución + notas).

`reports` (`IndicatorDashboardPage` — KPIs + `shared/components/charts/` wrappers,
filtros lazy; `NominalListPage` — tabla `useBaseList` + "Exportar a Excel" vía
`onReport()`) es el último módulo construido, sin catálogos propios sin par
(`screening_situation`/`age_group` se agregaron a `useCatalogs.ts::CatalogType`).

Casi todo es CRUD con el andamiaje compartido (`createEntityStore`, `createCrudApi`,
`useBaseList`, `useBaseCrud`). Sólo dos pantallas necesitan lógica propia:

- **`ScreeningApplyPage`** — el asistente de aplicación. Los campos los determina el
  servidor (los reactivos del grupo), hay un round-trip a mitad de flujo (sustitución
  por edad corregida, bloque alterno) y el resultado llega del backend. Usa un store
  plano, no `useBaseCrud`.
- **`AttendanceSheetPage`** — grilla niños × fecha con guardado masivo idempotente.
