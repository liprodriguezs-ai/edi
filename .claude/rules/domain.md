# Project Domain

EDI es una plataforma SaaS multi-tenant para administrar organizaciónes residenciales (organizacións y organizaciones) en México.

## Glossary

| Term | Meaning |
|------|---------|
| Tenant | El organización residencial suscripto a la plataforma. Todos los tenants comparten UNA sola base de datos; el aislamiento es por fila vía columna `tenant_id` + `TenantScope` |
| Company | Identidad legal/fiscal/branding del organización (razón social, RFC, logotipo, paleta de colores, acta constitutiva). Un tenant tiene una company principal |
| Unit | Vivienda individual dentro del organización (casa o departamento). Identificada por tipo + torre + piso + número |
| Unit User | Pivot que vincula un `user` con una `unit`. Contiene `relationship` (owner / co_owner / renter / family_resident / billing_contact), `is_billing_owner`, `is_legal_representative`, fechas y notas |
| User | Entidad central que representa CUALQUIER persona (residente, directivo, empleado, vigilante). Email y password son nullable (`auth_active` controla si puede autenticarse). PII por organización en `user_profile` (tenant-scoped) |
| User Profile | Tabla tenant-scoped con PII por organización: RFC, CURP, fecha de nacimiento, dirección, contacto de emergencia, notas |
| Employee | Empleado del organización (seguridad, jardinero, limpieza, mantenimiento, administrador, etc.). Repurpose del módulo Collaborator. Posición, salario, turno y fechas en inglés |
| Board Period | Período de la mesa directiva con fecha de inicio y fin. Al asignar un cargo (`board_member`) se otorga el rol Spatie global; al cerrar el período se revoca |
| Board Member | Cargo dentro de un `board_period`. Vincula un `user` con un rol Spatie (President, Treasurer, Secretary, Vocal, Oversight Committee) |
| Charge Concept | Concepto de cobro (mantenimiento, agua, seguridad, etc.) con `periodicity` (monthly/bimonthly/yearly/eventual), `amount_mode` y `grace_days` |
| Charge Batch | Lote de cobros generado a partir de un `charge_concept` con `target_type` (all/by_type/by_tower/by_floor/manual/by_feature/by_share/single_unit) |
| Charge | Cargo individual por unidad dentro de un `charge_batch`. Estado visual almacenado como FK a `charge_status` (tenant-scoped) |
| Payment | Pago registrado contra uno o más cargos. `receipt_number` con formato `R-YYYY-NNNNNN`. Método: cash/transfer/deposit/check |
| Payment Application | Tabla intermedia que implementa FIFO + parciales: vincula un `payment` con un `charge` y registra el `applied_amount` |
| Credit Balance | Saldo a favor por unidad. Se aplica automáticamente en el próximo cobro |
| Bank Account | Cuenta bancaria o caja del organización (una cuenta de tipo "caja" es válida). `current_balance` en minor units, recomputado transaccionalmente |
| Expense | Egreso del organización con `beneficiary_type` (employee/provider/other) y FK nullable a empleado, proveedor o proyecto |
| Provider | Proveedor de servicios del organización con `service_type`, `is_recurring`, `estimated_amount` |
| Community Project | Proyecto comunitario con meta de financiamiento. Puede ser obligatorio (genera cargos) o voluntario (acepta aportaciones libres) |
| Project Contribution | Aportación de una unidad a un `community_project`. Estado en `project_contribution_status` |
| Announcement | Comunicado o aviso del organización, despachado vía el módulo Notification |
| Chat Channel | Canal de chat comunitario (con canales de sistema no eliminables) |
| Visitor | Visita registrada con QR generado por el residente. Inmutable al registrarse en `access_log` |
| Access Log | Bitácora inmutable de accesos al organización |
| Blacklist Entry | Entrada en la lista negra de visitantes o vehículos |
| Scheduled Report | Reporte programado para generación automática |
| Subscription Plan | Plan de pago para acceder a la plataforma |
| Super Admin | Rol con acceso cross-tenant (administrador de la plataforma EDI) |
| Ownership Share | `indiviso` en México — porcentaje de copropiedad que determina la proporción de cobros cuando `uses_ownership_share = true` |
| Receipt Number | `folio_digital` — identificador único de pago con formato `R-YYYY-NNNNNN` |

## Main Entities

### Tenant
- **Attributes**: name, domain, status (active, pending_payment, blocked), `timezone` (def: America/Mexico_City), `currency` (def: MXN), `type` (horizontal/vertical/mixed), `uses_ownership_share` (bool), `payment_day`
- **Relationships**: has many Companies, Users (vía `user_tenant`). Tenant resolution = desde el usuario autenticado (`InitializeTenancyFromUser`); super-admin usa header `X-Tenant-Id`
- **Notes**: Status como class constants (no PHP Enum) por limitación de Stancl BaseTenant. NO hay BD por tenant — la tenencia es por fila. Datos legales/branding del organización viven en la `company` principal

### Company
- **Attributes**: legal_name, rfc, address, logo, colors, tagline, constitutive_deed
- **Relationships**: belongs to Tenant. Es la identidad legal/fiscal/branding del organización. Un tenant tiene una company principal
- **Notes**: Editada por el módulo de Configuración del Organización (Módulo 12)

### User
- **Attributes**: name, email (nullable, partial unique), password (nullable), phone, avatar, `auth_active` (bool), `onboarding_completed_at`, `last_active_tenant_id`
- **Relationships**: belongs to many Tenants (vía `user_tenant`), has many Roles (Spatie global), has many Units (vía `unit_user`), has one UserProfile per tenant
- **Roles EDI (11)**: Super Admin, Administrator, President, Treasurer, Secretary, Vocal, Oversight Committee, Security, Operations Staff, Resident, Renter
- **Notes**: "Everything is User" — no hay entidad Person separada. `email`/`password` nullable porque no toda persona necesita autenticarse

### Unit
- **Attributes**: type, number, tower_id, floor, section_id, built_m2, land_m2, `ownership_share` (decimal 7,4), `unit_status_id` (FK a `unit_status`), `charge_status_id` (FK a `charge_status` — estado agregado "worst wins"), notes
- **Relationships**: belongs to Tenant, Tower, Section. Has many UnitUsers, Vehicles, Charges
- **Unique**: `[tenant_id, type, tower_id, floor, number]`

### Charge
- **Attributes**: `charge_batch_id`, `unit_id`, `expected_amount` (bigint minor units), `paid_amount` (bigint minor units), `due_date`, `issued_at`, `charge_status_id` (FK a `charge_status` tenant-scoped)
- **Relationships**: belongs to ChargeBatch, Unit, ChargeStatus. Has many PaymentApplications
- **Notes**: `charge_status_id` es escrito por `ChargeStatusService` en la misma transacción cuando se aplica/cancela un pago o cuando el cron diario corre la transición temporal (pending→due_soon→overdue)

### Payment
- **Attributes**: `unit_id`, `bank_account_id`, `total_amount` (bigint minor units), `method` (cash/transfer/deposit/check), `receipt_number` (R-YYYY-NNNNNN), `physical_receipt_number`, `paid_at`, `payment_status_id` (FK a `payment_status`)
- **Relationships**: belongs to Unit, BankAccount, PaymentStatus. Has many PaymentApplications

### SubscriptionPlan
- **Attributes**: name, price, features, stripe_price_id, interval
- **Relationships**: has many Subscriptions (vía Cashier)

## Business Flows

### Onboarding de Organización
1. El usuario registra el organización y crea su cuenta de Administrador
2. Se crea el tenant (fila en la BD compartida; NO se crea una BD separada)
3. El evento `TenantCreated` ejecuta `tenants:seed` → siembra catálogos de estado por tenant (`charge_status`, `payment_status`, etc.) y configuraciones por defecto
4. El administrador completa la configuración del organización (company: datos legales/branding, `tenant_collection_setting`: días de gracia, vencimiento, etc.)
5. El tenant elige un plan de suscripción (Stripe Checkout) y queda activo

### Gestión de Viviendas y Residentes
1. El administrador da de alta la estructura física: torres, secciones, unidades
2. Se registran las personas (`user`) asociadas a cada unidad (vía `unit_user` pivot)
3. Se configura el `relationship` (owner/co_owner/renter/family_resident/billing_contact) y el `is_billing_owner`
4. Se registran vehículos por unidad
5. Cambios auditados vía GlobalObserver + Laravel Auditing

### Cobranza (FIFO + saldo a favor)
1. El administrador crea un `charge_concept` con monto, periodicidad y días de gracia
2. Se genera un `charge_batch` con el `target_type` (all/by_type/by_tower/by_floor/manual/by_feature/by_share/single_unit)
3. El `TargetResolver` determina las unidades alcanzadas; el `AmountCalculator` calcula el monto (fijo, proporcional por `ownership_share`, o prorrateado)
4. Se crean los `charge` individuales por unidad con estado inicial `pending` (FK a `charge_status`)
5. Al registrar un `payment`, el sistema aplica FIFO (orden: `due_date` asc → `issued_at` asc → `id`) y primero consume cualquier `credit_balance`
6. La `PaymentApplication` registra el `applied_amount` por cargo
7. `ChargeStatusService` recomputa `charge.charge_status_id` y `unit.charge_status_id` (worst-wins) en la misma transacción
8. El cron diario por tenant hace las transiciones temporales: `pending→due_soon` (≤ `due_soon_days`) y `→overdue` (post `due_date` + `grace_days`)

### Mesa Directiva
1. El administrador abre un nuevo `board_period` con fechas
2. Al asignar un `board_member` → `$user->assignRole('President')` (Spatie global)
3. Al cerrar el período → `$user->removeRole(...)` de todos los `board_member` del período
4. Limitación: el rol Spatie es global (no per-tenant). Aceptado por decisión del equipo

### Tesorería
1. El tesorero registra `bank_account` (cuentas bancarias y caja)
2. Los pagos confirmados incrementan el `bank_account.current_balance` (minor units, recomputado transaccionalmente)
3. Los egresos (`expense`) decrementan el balance; pueden vincularse a empleado, proveedor o proyecto comunitario
4. Los reportes muestran el estado de cuenta del organización

### Suscripción y Facturación
1. El tenant elige un plan de suscripción
2. El pago se procesa vía Stripe (Cashier)
3. Si el pago falla → estado `pending_payment`; si sigue sin pagar → `blocked`
4. `SubscriptionGuard` en el frontend restringe el acceso

## Business Rules

### Multi-tenancy
Multi-tenancy: UNA sola BD compartida por todos los tenants (single-database). Aislamiento por fila vía columna `tenant_id` + `TenantScope` (trait `BelongsToTenant`). NO hay BD por tenant ni switching de conexión. Mecanismo canónico en stancl-tenancy skill.

### Naming (HARD — English identifiers only)
- **TODOS los identificadores en inglés**: nombres de tablas (singular), columnas, clases de modelos, métodos de relaciones, claves de estado/enum, nombres de servicios/jobs/eventos
- **Español SOLO en**: texto de UI y valores de columnas `label`/`name`/`description` (los datos que ve el usuario)
- Disambiguaciones críticas: `inquilino` → `renter` (NO `tenant`); `indiviso` → `ownership_share`; `folio` → `receipt_number` / `physical_receipt_number`

### Money
- Todas las columnas de dinero son `bigInteger` en **minor units** (centavos: `150000` = $1,500.00 MXN)
- Cast: `Cknow\Money\Casts\MoneyIntegerCast` (paquete `cknow/laravel-money`)
- NUNCA usar `decimal` o `float` para dinero

### Statuses / Enums — CENTRAL vs TENANT-SCOPED
- **Catálogos de estado FSM y selectores de comportamiento = CENTRALES** (`BaseModel::columns($table, false)`, sin `BelongsToTenant`, **constantes UUID** fijas en el modelo ej. `ChargeStatus::OVERDUE`, sembrados UNA vez por `CatalogSeeder`, read-only para roles del organización). El código ramifica sobre estas filas, así que no son editables por el tenant.
- **Catálogos de etiquetas administrables = TENANT-SCOPED** (`BaseModel::columns($table, true)` + `BelongsToTenant`, `unique(['tenant_id','name'])`, sembrados POR tenant por `TenantCatalogSeeder` en `TenantCreated`, editables por el admin). La lógica de negocio vive en columnas (`requires_floor`, `is_billable`, `grants_role`, `key`), no en UUIDs.
- Regla de decisión: si el backend ramifica sobre la fila (FSM/comportamiento) → central; si es clasificación libre sin lógica → tenant-scoped. Contrato: `CatalogSeeder` (central) vs `TenantCatalogSeeder` (tenant).
- NUNCA: constantes hardcoded fuera de los modelos de catálogo, PHP Enums nativos, vistas/cómputo on-read.

### Catálogos de estado actuales (FSM — CENTRALES, salvo `unit_status`)

Todos estos son FSM de estados. Son CENTRALES (UUID fijo, `CatalogSeeder`),
EXCEPTO `unit_status` que es TENANT-SCOPED (el organización define sus estados de
unidad; la facturabilidad vive en la columna `is_billable`).

| Tabla catálogo | Scope | Claves (EN) | Módulo dueño |
|---|---|---|---|
| `charge_status` | central | completed, partial, overdue, due_soon, overpaid, pending, payment_plan | Collections |
| `payment_status` | central | confirmed, pending_validation, cancelled | Collections |
| `charge_batch_status` | central | draft, active, cancelled | Collections |
| `community_project_status` | central | draft, active, fully_funded, executed, cancelled | Community Projects |
| `project_contribution_status` | central | pending, partial, ideal, generous, below_min, declined | Community Projects |
| `board_period_status` | central | current, closed | Board & Staff |
| `unit_status` | **tenant-scoped** | active, vacant, under_construction, inactive (+ `is_billable`) | Housing |

### Permissions
- Permisos granulares por módulo (CRUD por recurso)
- `$keyForPermission` en los controladores Orion mapea automáticamente a `{key}.create`, `{key}.read`, etc.
- **11 roles EDI**: Super Admin, Administrator, President, Treasurer, Secretary, Vocal, Oversight Committee, Security, Operations Staff, Resident, Renter
- Los roles de directiva (President, Treasurer, Secretary, Vocal, Oversight Committee) son otorgados/revocados por `board_member` al abrir/cerrar `board_period`
- Los roles de relación con la unidad (owner/renter) son columnas en `unit_user`, NO roles Spatie

### Auditing
- Toda operación CRUD registra quién la realizó (`user_created_id`, `user_updated_id`, etc.)
- GlobalObserver corre automáticamente en todos los modelos
- Borrados son soft (los datos nunca se eliminan físicamente)
- Laravel Auditing registra cambios detallados (antes/después)
