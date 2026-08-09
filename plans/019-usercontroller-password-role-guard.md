# Plan 019: Validar `new_password` en `updatePassword` y corregir el guard invertido de `ACCOUNT_OWNER`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat e41e668..HEAD -- Modules/User/app/Http/Controllers/UserController.php`
> If the file changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED (lógica de autorización y contraseñas; el fix es chico pero el área es sensible)
- **Depends on**: none
- **Category**: security
- **Planned at**: backend submodule `e41e668`, 2026-06-12

## Why this matters

Dos bugs confirmados en el mismo controlador:

1. `updatePassword` valida SOLO la contraseña actual. `new_password` no tiene
   regla alguna: sin mínimo de longitud, sin confirmación, y si el caller lo
   omite, `Hash::make($request->new_password)` recibe `null` y la contraseña
   del usuario queda hasheada como cadena vacía. Cualquier usuario autenticado
   puede dejarse (o dejar a quien engañe) una contraseña vacía/débil.
2. El guard de `afterUpdate` que debería impedir que un NO-superadmin asigne o
   quite el rol `ACCOUNT_OWNER` está invertido: dispara 403 cuando un
   **super admin** cambia roles comunes (operación que debe permitirse) y NO
   bloquea la escalación a owner por parte de un no-superadmin.

## Current state

- `backend/Modules/User/app/Http/Controllers/UserController.php` — controlador Orion del módulo User.

Guard invertido (líneas 197–220):

```php
protected function afterUpdate(Request $request, Model $entity)
{
    $user = auth()->user();
    $currentRole = $entity->roles()->first();

    if ($user->hasRole(\QCH\Core\Models\Role::SUPER_ADMIN) && $currentRole->uuid != $request->role_id && $currentRole->uuid != \QCH\Core\Models\Role::ACCOUNT_OWNER && $request->role_id != \QCH\Core\Models\Role::ACCOUNT_OWNER) {
        return response()->json([
            'message' => 'Solo el superadmin puede cambiar el rol de un propietario.',
        ], 403);
    }
    // ... detach/assignRole + SendAccountOwnerNotificationJob
}
```

Nótese además que `$currentRole` puede ser `null` (usuario sin rol) → null
deref en `$currentRole->uuid`.

`updatePassword` sin validación del valor nuevo (líneas 305–343):

```php
public function updatePassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'password' => 'required',          // SOLO la actual
    ]);
    // ...
    /*if ($user->update_password) { ... }*/   // código muerto comentado (319–323)
    if (! Hash::check($request->password, $user->password)) { ... }
    $user->password = Hash::make($request->new_password);   // sin validar
    // $user->update_password = 1;             // código muerto (333)
    $user->save();
    $user->tokens()->delete();
```

El patrón CORRECTO ya existe en el mismo archivo — `resetPassword` (líneas 222–249):

```php
$validator = Validator::make($request->all(), [
    'current_password' => 'required',
    'new_password' => 'required|confirmed',
]);
```

Convención del proyecto: respuestas JSON planas `response()->json([...])` para
rutas custom (no Orion); ver `.claude/rules/laravel-architecture.md` §4.5.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test filtrado | `cd backend && php artisan test --filter=UserPasswordAndRoleGuardTest` | all pass |
| Suite de seguridad | `cd backend && php artisan test tests/Feature/Security/` | all pass |
| Formato | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope** (only files you may modify):
- `backend/Modules/User/app/Http/Controllers/UserController.php`
- `backend/tests/Feature/User/UserPasswordAndRoleGuardTest.php` (create)
- SOLO si el paso 1 lo exige: el componente frontend que llama al endpoint
  (agregar el campo de confirmación al payload).

**Out of scope**:
- Eliminar/unificar `resetPassword` vs `updatePassword` (queda como nota de
  mantenimiento — puede haber clientes activos de ambos endpoints).
- Cualquier otro método del controlador (`authorize`, `setTokenSender`, etc.).

## Git workflow

- Los commits van DENTRO del submódulo `backend/` (es un repo git propio).
- Conventional commits, sin AI attribution. Ej: `fix(user): validate new_password and correct account-owner role guard`.
- Si el commit falla por firma/hooks en contexto headless: `--no-gpg-sign --no-verify`.

## Steps

### Step 1: Identificar rutas y caller frontend de `updatePassword`

`grep -rn "updatePassword\|update-password\|resetPassword\|reset-password" backend/Modules/User/routes/ backend/routes/`
y `grep -rn "new_password" frontend/src/ | grep -v node_modules`.

Anotar: (a) qué ruta apunta a cada método; (b) si el frontend envía
`new_password_confirmation` en el flujo "cambiar contraseña". Si NO lo envía,
en el Step 2 usar las reglas SIN `confirmed` y agregar el campo de
confirmación en el formulario frontend (el formulario de cambio de contraseña
ya pide confirmación visualmente en la mayoría de estos flujos — verificar).

**Verify**: tenés la tabla ruta→método y la lista de campos que envía el front.

### Step 2: Validar `new_password` en `updatePassword`

En el validador de `updatePassword` agregar:

```php
'password' => 'required',
'new_password' => ['required', 'string', 'min:8', 'confirmed'],
```

(`confirmed` solo si el frontend envía `new_password_confirmation` — Step 1).
Eliminar los bloques comentados de `update_password` (líneas 319–323 y 333).

**Verify**: `cd backend && vendor/bin/pint --dirty` → exit 0.

### Step 3: Corregir el guard de `afterUpdate`

Reemplazar la condición de la línea 202 por la semántica documentada
("solo superadmin puede tocar el rol owner"), con null-safety:

```php
$currentRole = $entity->roles()->first();
$ownerInvolved = ($currentRole?->uuid === \QCH\Core\Models\Role::ACCOUNT_OWNER)
    || ($request->role_id === \QCH\Core\Models\Role::ACCOUNT_OWNER);

if ($ownerInvolved && ! $user->hasRole(\QCH\Core\Models\Role::SUPER_ADMIN)) {
    return response()->json([
        'message' => 'Solo el superadmin puede cambiar el rol de un propietario.',
    ], 403);
}
```

NO tocar el resto del método (detach/assign/notificación).

**Verify**: `php artisan test tests/Feature/Security/` → all pass (sin regresión).

### Step 4: Tests

Crear `backend/tests/Feature/User/UserPasswordAndRoleGuardTest.php` (patrón
estructural: `tests/Feature/Security/TenantIsolationTest.php` — usa
`RefreshDatabase`, helpers de tenant y `tenantRequest`). Casos:

1. `updatePassword` sin `new_password` → 422 (hoy pasa con 200: ESTE es el bug).
2. `updatePassword` con `new_password` de 3 chars → 422.
3. `updatePassword` happy path → 200, `Hash::check` del nuevo valor true, tokens revocados.
4. Usuario con rol Administrator (no superadmin) hace PUT del user cambiando
   `role_id` a `ACCOUNT_OWNER` → 403.
5. Superadmin cambia el rol de un usuario Resident → otro rol común → 200 (hoy
   devuelve 403: ESTE es el bug).

**Verify**: `php artisan test --filter=UserPasswordAndRoleGuardTest` → 5 pass.

## Done criteria

- [ ] `php artisan test --filter=UserPasswordAndRoleGuardTest` → 5/5 pass
- [ ] `php artisan test tests/Feature/Security/` → pass
- [ ] `grep -n "update_password" backend/Modules/User/app/Http/Controllers/UserController.php` → 0 matches
- [ ] `vendor/bin/pint --dirty` → exit 0
- [ ] `plans/README.md` status row updated

## STOP conditions

- Los excerpts de "Current state" no coinciden con el código vivo.
- No existe ruta registrada para `updatePassword` (sería código muerto: reportar
  y proponer borrarlo en vez de arreglarlo).
- El frontend envía un shape distinto a `{password, new_password, ...}` (contrato
  distinto al asumido).
- Algún test de `tests/Feature/Security/` rompe tras el Step 3 y el fix no es
  obvio en un intento.

## Maintenance notes

- Follow-up deferido: unificar `resetPassword`/`updatePassword` en un solo
  endpoint bien validado y deprecar el otro (verificar consumidores primero).
- El reviewer debe escrutar la tabla de verdad del nuevo guard: owner
  involucrado × actor superadmin/no — 4 combinaciones, solo una da 403... por
  cada dirección (asignar owner / quitar owner).
