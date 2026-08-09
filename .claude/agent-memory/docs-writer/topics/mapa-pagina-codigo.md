---
name: mapa-pagina-codigo
description: Qué archivo de código respalda cada página del manual; se usa para saber qué documentación revisar cuando algo cambia
metadata:
  type: reference
---

# Mapa página → código fuente

Sirve para dos cosas: verificar un dato antes de escribirlo, y saber qué páginas
revisar cuando un archivo cambia. Es la base del hook `docs-drift-check.sh`.

## Fuentes transversales

Un cambio en cualquiera de estas **puede afectar a muchas páginas a la vez**:

| Archivo | Qué desactualiza |
|---|---|
| `backend/database/seeders/RolePermissionTableSeeder.php` | Los bloques "Quién puede usarlo" de **todas** las páginas, y toda la sección Roles |
| `backend/database/seeders/csv/modules.csv` | Los nombres del menú citados en `empezar/como-se-organiza` y en cada página |
| `backend/database/seeders/CatalogSeeder.php` | Etiquetas de estados centrales: cargos, pagos, lotes, reservaciones, visitantes, paquetes |
| `backend/database/seeders/TenantCatalogSeeder.php` | Catálogos por tenant: tipos y estados de unidad, categorías, motivos |
| `frontend/src/App.tsx` | Rutas y permisos de pantalla |
| `frontend/src/modules/configuration/configSections.tsx` | Toda la sección Configuración |
| `backend/Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder.php` | `cuenta/planes-y-precios` y `empezar/crear-cuenta-y-plan` |

## Por sección

| Páginas | Código que las respalda |
|---|---|
| `empezar/configuracion-inicial` | `backend/Modules/Dashboard/app/Services/OnboardingChecklistService.php` |
| `empezar/crear-cuenta-y-plan` | `frontend/src/modules/checkoutSubscription/**`, `frontend/src/components/signup-form.tsx` |
| `cobranza/**` | `frontend/src/modules/{charge,chargeBatch,chargeConcept,chargePolicy,payment,collections}/**`, `backend/Modules/Collections/**` |
| `cobranza/importacion-de-pagos` | `frontend/src/modules/import/**`, `backend/Modules/DataImport/**` |
| `tesoreria/**` | `frontend/src/modules/{bankAccount,expense,expenseCategory,provider}/**`, `backend/Modules/{Treasury,Providers}/**` |
| `estructura/**` | `frontend/src/modules/{unit,unitUser,unitType,unitStatus,unitFeature,tower,section,vehicle,userProfile,residentInvitation}/**`, `backend/Modules/Housing/**` |
| `estructura/limite-de-unidades` | `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` |
| `accesos/**` | `frontend/src/modules/access/**`, `backend/Modules/Access/**` |
| `paqueteria/**` | `frontend/src/modules/package/**`, `backend/Modules/Package/**` |
| `amenidades/**` | `frontend/src/modules/{amenity,reservation}/**`, `backend/Modules/AmenityReservations/**` |
| `comunicacion/**` | `frontend/src/modules/{announcement,chat,notification}/**`, `backend/Modules/{Announcements,Chat,Notification}/**` |
| `proyectos/**` | `frontend/src/modules/communityProject/**`, `backend/Modules/Projects/**` |
| `incidencias/**` | `frontend/src/modules/complaint/**`, `backend/Modules/Complaint/**` |
| `mesa-y-personal/**` | `frontend/src/modules/{boardPeriod,collaborator}/**`, `backend/Modules/{Board,Collaborator}/**` |
| `reportes/**` | `frontend/src/modules/{reports,dashboards}/**`, `backend/Modules/Reports/**` |
| `configuracion/**` | `frontend/src/modules/configuration/**`, `backend/Modules/Configuration/**` |
| `cuenta/**` | `frontend/src/modules/{user,checkoutSubscription,billingPackage}/**`, `backend/Modules/SubscriptionPlan/**` |
| `cuenta/acceso-en-pausa` | `frontend/src/modules/checkoutSubscription/SelectPlanPage.tsx` (los tres estados bloqueados + `MemberBlockedView`), `backend/Modules/SubscriptionPlan/app/Services/SubscriptionStateService.php` |
| `configuracion/plan` | `frontend/src/modules/configuration/components/PlanSummarySection.tsx` |

## Expertos a los que conviene preguntar

En lugar de reconstruir el detalle de un módulo con experto propio, pídeselo:

- `collections-expert` — todo lo de cobranza
- `notifications-expert` — notificaciones, canales, avisos como notificación
- `email-expert` — plantillas de correo
