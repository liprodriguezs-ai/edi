# Plan 020: Allowlist de MIME en el upload de adjuntos de avisos

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. On any
> STOP condition, stop and report. When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat e41e668..HEAD -- Modules/Announcements/`
> Si cambió algo, comparar los excerpts de "Current state" con el código vivo;
> ante mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW (validación aditiva; uploads válidos no cambian)
- **Depends on**: none
- **Category**: security
- **Planned at**: backend submodule `e41e668`, 2026-06-12

## Why this matters

`POST /announcement/{id}/media` acepta CUALQUIER tipo de archivo: el controlador
valida solo `file|max:10240` y el servicio (`AnnouncementService::attachFile`)
valida solo tamaño y tope de 5 adjuntos — verificado en ambos. Un usuario con
`update.announcement` puede subir `.php`, `.exe` o SVG con `<script>` embebido
al media library (Spatie) del clúster. El ciclo de audit anterior (plan 002,
DONE) instauró allowlists MIME en los demás uploads; este endpoint es posterior
y regresionó la convención.

## Current state

- `backend/Modules/Announcements/app/Http/Controllers/AnnouncementController.php` — líneas 160–181:

```php
public function uploadMedia(string $id, Request $request): JsonResponse
{
    $this->authorize('update.announcement', Announcement::class);

    $request->validate([
        'file' => ['required', 'file', 'max:10240'],   // ← SIN mimes
    ]);

    $announcement = Announcement::findOrFail($id);

    try {
        $media = app(AnnouncementService::class)->attachFile($announcement, $request->file('file'));
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    return response()->json(['data' => $media], 201);
}
```

- `backend/Modules/Announcements/app/Services/AnnouncementService.php` — líneas 232–250:
  `attachFile` valida `getSize() > 10MB` y `>= MAX_ATTACHMENTS (5)`; ningún
  chequeo de tipo.

Exemplares de la convención ya instaurada (plan 002) para copiar el estilo de
allowlist: `Modules/Collections/app/Http/Controllers/PaymentController.php`
(~línea 64, comprobante de pago) y
`Modules/Board/app/Http/Controllers/BoardPeriodController.php` (~línea 122,
`mimetypes:application/pdf` para el acta). Leer ambos y reusar el formato.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests del módulo | `cd backend && php artisan test tests/Feature/Announcements/` | all pass |
| Formato | `cd backend && vendor/bin/pint --dirty` | exit 0 |

(Si `tests/Feature/Announcements/` no existe con ese nombre exacto, localizarlo:
`ls backend/tests/Feature/ | grep -i announc`.)

## Scope

**In scope**:
- `backend/Modules/Announcements/app/Http/Controllers/AnnouncementController.php` (regla de validación)
- `backend/Modules/Announcements/app/Services/AnnouncementService.php` (defensa en profundidad, opcional pero recomendado)
- El archivo de test del módulo Announcements (agregar casos)

**Out of scope**:
- Otros endpoints de media de otros módulos (ya cubiertos por plan 002).
- Cambiar el límite de tamaño o el tope de 5 adjuntos.

## Git workflow

- Commit dentro del submódulo `backend/`. Conventional commit, ej:
  `fix(announcements): restrict attachment upload to safe mime allowlist`.

## Steps

### Step 1: Allowlist en el controlador

En `uploadMedia`, reemplazar la regla por:

```php
'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx', 'max:10240'],
```

Ajustar la lista para que sea consistente con la de `PaymentController` /
`BoardPeriodController` (leerlos primero — si el proyecto consolidó una lista
canónica, usarla; la de arriba es el fallback).

**Verify**: `vendor/bin/pint --dirty` → exit 0.

### Step 2: Defensa en profundidad en el servicio

En `AnnouncementService::attachFile`, antes del `addMedia`, rechazar extensiones
peligrosas aunque el controlador ya filtre (otra ruta de código podría llamar al
servicio):

```php
$allowed = ['pdf','jpg','jpeg','png','webp','gif','mp4','mp3','doc','docx','xls','xlsx'];
if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
    throw new \InvalidArgumentException('Tipo de archivo no permitido.');
}
```

**Verify**: `php artisan test tests/Feature/Announcements/` → pass (sin regresión).

### Step 3: Tests

En el test del módulo Announcements (modelar según los tests de upload
existentes del módulo Board o Collections — buscan `UploadedFile::fake()`):

1. Subir `UploadedFile::fake()->create('malicious.php', 10, 'application/x-php')` → 422.
2. Subir `UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml')` → 422 (SVG queda fuera del allowlist a propósito: puede embeber JS).
3. Subir `UploadedFile::fake()->create('ok.pdf', 100, 'application/pdf')` → 201.

**Verify**: `php artisan test tests/Feature/Announcements/` → all pass incl. 3 nuevos.

## Done criteria

- [ ] `grep -n "mimes:" backend/Modules/Announcements/app/Http/Controllers/AnnouncementController.php` → 1 match
- [ ] `php artisan test tests/Feature/Announcements/` → pass con los 3 casos nuevos
- [ ] `vendor/bin/pint --dirty` → exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Los excerpts no coinciden con el código vivo.
- Existe un requerimiento de producto documentado de aceptar tipos fuera del
  allowlist (buscar en `plans/018-system-audit-findings.md` y specs §12) — en
  ese caso reportar la lista propuesta en vez de decidirla.
- `UploadedFile::fake()` no funciona en el test del módulo (infra de testing
  distinta) tras un intento razonable.

## Maintenance notes

- Si el producto pide nuevos tipos (p.ej. `zip`), agregarlos en LOS DOS lugares
  (controller + service) — el reviewer debe verificar que ambos listados queden
  sincronizados.
- Follow-up natural: extraer la allowlist a `config/media.php` compartida por
  todos los módulos con upload.
