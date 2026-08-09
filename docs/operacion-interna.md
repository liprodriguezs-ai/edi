# Operación interna de Kivo7 — manual de super admin

> **Este documento NO se publica.** Vive aquí a propósito, fuera de
> `landing/src/content/docs/`, porque describe la trastienda de la plataforma:
> cómo se provisiona un condominio, cómo se editan los precios, cómo se registra
> un pago manual y cómo se otorga acceso gratis. Nada de esto debe ser visible
> para un cliente.
>
> El manual público —lo que sí ve el cliente— está en `kivo7.com/docs` y se
> escribe siguiendo `landing/DOCS-STYLE-GUIDE.md`.

Cubre los módulos del sistema **Core**, que solo ve el rol **Super Admin**.

---

## 1. Tenants (condominios)

Un tenant es un condominio suscrito a la plataforma. Todos comparten una sola
base de datos; el aislamiento es por fila.

### Provisionar uno nuevo

Pantalla: **Tenants → Nuevo Tenant** (`/tenant/new`), un asistente que incluye el
selector de plan.

Al crearse, el evento `TenantCreated` siembra automáticamente:

- Los catálogos por tenant (tipos y estados de unidad, categorías, motivos de
  visita, etc.) vía `TenantCatalogSeeder`.
- Las configuraciones por defecto de cada módulo que tenga sección de
  configuración.

Los catálogos **centrales** (estados de cargo, de pago, métodos de pago,
relaciones de unidad) no se siembran por tenant: son globales y de escritura
exclusiva de la plataforma.

### Módulos y sistemas por tenant

- Activar o desactivar un módulo individual: `PATCH /tenant/{id}/modules/{moduleId}`
  y `POST /tenant/{id}/modules/sync`.
- Agregar o quitar un sistema completo como add-on:
  `POST` / `DELETE /tenant/{id}/systems/addon`.

El menú que ve cada usuario (`GET /api/menu`) se arma cruzando: sistemas del
tenant × módulos habilitados × permisos del rol.

### Estados de un tenant

`active`, `pending_payment`, `blocked`. Son constantes de clase, no un enum de
PHP, por una limitación de `BaseTenant` de Stancl.

---

## 2. Panel de facturación por tenant

Es la pantalla más delicada de todas. Permite:

| Acción | Cuándo se usa |
|---|---|
| **Otorgar acceso gratis** (entitlement) | Cortesías, casos de soporte, pruebas extendidas |
| **Iniciar prueba** | Extender o reiniciar el periodo de prueba |
| **Revocar** | Quitar un entitlement otorgado |
| **Registrar pago manual** | Cuando el condominio paga por transferencia o en efectivo |
| **Cambiar método de pago** | Redirige al portal de Stripe |
| **Cancelar suscripción** | Al final del ciclo o de inmediato |
| **Swap preferencial** | Cambiar de plan con condiciones especiales, con vista previa |
| **Ver historial de cobros** | Fecha, método, monto, hasta cuándo cubre, notas |

**Pago manual**: se captura el método (Transferencia o Efectivo), el concepto, el
monto y hasta qué fecha cubre. Es el mecanismo para condominios que no pagan con
tarjeta.

> ⚠️ Todo lo de este panel mueve dinero o acceso. Deja siempre nota de por qué se
> hizo: es lo único que va a existir cuando alguien pregunte en seis meses.

---

## 3. Planes y precios

Pantalla: **Planes y Precios** (`/billing-package`).

### Catálogo actual

| Plan | Unidades | Mensual |
|---|---|---|
| Básico | 1–25 | $450.00 MXN |
| Inicial | 26–50 | $650.00 MXN |
| Esencial | 51–100 | $950.00 MXN |
| Avanzado | 101–200 | $1,550.00 MXN |
| Premium | 201–300 | $2,100.00 MXN |
| A medida | 301+ | sin precio de lista |

El precio anual se calcula como `mensual × 12 × 0.95` — doce meses con 5% de
descuento. Las constantes son `ANNUAL_MONTH_COUNT` y `ANNUAL_PRICE_FACTOR` en
`PlanModuleMatrixSeeder.php`.

**"A medida" no tiene fila de precio a propósito**: esa ausencia es lo que lo
marca como venta consultiva. Cotizar o hacer checkout contra él devuelve 422 con
`contact_sales`.

### Editar la matriz de precios

`PUT /billing/plan/{id}/price-matrix`, desde el componente `PlanPriceMatrix`.
Permite editar el precio por plan e intervalo.

> Después de tocar precios hay que verificar que el catálogo de Stripe siga
> alineado. Tras un `migrate:fresh` se corre `php artisan billing:sync-stripe`
> para repoblar los `stripe_price_id`.

### Módulo facturable

Desde el refactor de 2026-07 hay **un solo** módulo facturable, `base`. Paquetería
y Amenidades quedaron incluidas en el plan; ya no hay add-ons por módulo de cara
al cliente. El precio depende **solo** del rango de unidades.

---

## 4. Roles y permisos

Pantalla: **Roles** (`/role`, `/role/:id`), exclusiva del Super Admin.

Los permisos se siembran desde CSV y se asignan en
`backend/database/seeders/RolePermissionTableSeeder.php`. Reglas del modelo:

- **El Super Admin no tiene comodín `*`.** Tiene permisos específicos:
  `$permissionsOnlySuperAdmin` más `$commonPermissions`.
- **Propietario y Admin Empresa** reciben todos los permisos **excepto** los
  exclusivos de super admin, vía `whereNotIn`.
- Los demás roles del condominio reciben listas explícitas: lo que no está
  escrito, no lo tienen.

Para volver a sembrar: `php artisan db:seed --class=ResetPermissionTableSeeder`.

> Un permiso que no existe en `permissions.csv` hace que su `PermissionGuard`
> oculte el botón **para todos**, incluido el Administrador. Ya pasó con
> `change_owner.unit`. Al agregar un guard nuevo, verifica que el permiso esté
> sembrado.

---

## 5. Usuarios y privacidad

Pantalla: **Usuarios** (`/user`).

Dos acciones sensibles:

- **Anonimizar usuario** (`POST /user/{id}/anonymize`) — para solicitudes de
  derecho al olvido. Es irreversible.
- **Revocar sesiones** (`POST /user/{id}/revoke-sessions`) — cierra todas las
  sesiones activas de esa persona.

Recuerda que **bloquear a un residente** (acción del Administrador del
condominio) también deshabilita la cuenta y cierra sesiones, pero a nivel de toda
la plataforma.

---

## 6. Auditoría

Pantalla: **Auditoría** (`/audit`). Bitácora de cambios de solo lectura, con
diff antes/después y exportación a Excel.

Los datos personales sensibles (RFC, CURP, dirección, contacto de emergencia)
**se excluyen a propósito** del payload auditado: la bitácora guarda quién cambió
un perfil y cuándo, nunca los valores.

---

## 7. Correos

Pantalla: **Correos** (`/`, módulo Email).

Los correos transaccionales se escriben en **React Email** (`frontend/emails/*.tsx`)
y se compilan a Blade con `pnpm copy-emails`. **Nunca se edita el `.blade.php` a
mano**: es salida generada y se pierde en la siguiente compilación.

Ver la regla estricta 10 de `AGENTS.md` y el agente `@email-expert`.

---

## 8. Entornos y comandos

| Qué | Cómo |
|---|---|
| Levantar todo | `./start.sh` |
| **Ver las URLs reales** | `./backend/bin/urls.sh` — lee los puertos de Docker en vivo |
| Tests de backend | `php artisan test` desde el host (no sail) — **un solo proceso a la vez** sobre la BD `testing` |
| Seed de permisos | `php artisan db:seed --class=ResetPermissionTableSeeder` |
| Catálogo de Stripe | `php artisan billing:sync-stripe` |

**No hay URLs fijas que anotar aquí.** Los puertos los publica Docker y pueden
moverse; la IP de la máquina la cambia DHCP. Sácalos siempre así:

```bash
./backend/bin/urls.sh          # puertos reales
ipconfig getifaddr en0          # IP de LAN (macOS)
```

La app React se abre **por la IP de LAN, nunca por `localhost`**: la cookie de
refresh de Sanctum está ligada al origen, y mezclar `localhost` (app) con la IP
(API) tira la sesión al primer refresh.

El detalle completo y cómo diagnosticar un desfase de IP están en `AGENTS.md`,
sección *Entornos de desarrollo local & QA*.

---

## Pendiente

Este documento se escribió junto con el manual público (2026-08) y cubre lo
esencial. Falta detallar, si alguna vez hace falta:

- El flujo completo de webhooks de Stripe y cómo reconciliar un pago fallido.
- El proceso de baja definitiva de un tenant.
- La operación del cron de reportes programados y de transición de estados.
