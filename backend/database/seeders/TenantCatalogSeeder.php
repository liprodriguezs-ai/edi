<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Access\Models\VisitorMotive;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Collaborator\Models\EmployeePosition;
use Modules\Collaborator\Models\EmployeeShift;
use Modules\Collaborator\Models\PayFrequency;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Package\Models\PackageCarrier;
use Modules\Package\Models\PackageType;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\Setting\Models\Setting;
use Modules\Treasury\Models\BankAccountPurpose;

/**
 * Carga inicial de los 12 catálogos AUTOADMINISTRABLES (tenant-scoped).
 *
 * Corre DENTRO de tenancy (TenantCreated → tenants:seed → TenantDatabaseSeeder),
 * así BelongsToTenant estampa el tenant_id solo. Es idempotente: las filas con
 * `key` (de sistema) upsertean por key — sobreviven renombres del admin; las
 * demás upsertean por name.
 *
 * El admin del clúster puede renombrar, recolorear, desactivar, reordenar o
 * agregar filas a gusto; las filas de sistema (key) no pueden borrarse
 * (HasCatalogKey). Los catálogos con FSM/comportamiento NO viven aquí — son
 * centrales y los siembra CatalogSeeder una sola vez.
 */
class TenantCatalogSeeder extends Seeder
{
    /**
     * Defaults declarativos por catálogo. Columnas especiales:
     *  - key:            fila de sistema (no borrable, invisible en UI)
     *  - grants_role:    rol Kivo7 que otorga el alta de empleado (null → operations_staff)
     *  - is_billable:    la unidad con este estado recibe cargos
     *  - requires_floor: el tipo exige torre/piso/número (vertical)
     *
     * @var array<class-string, array<int, array<string, mixed>>>
     */
    public const CATALOGS = [
        UnitType::class => [
            ['name' => 'Casa', 'order' => 0],
            ['name' => 'Departamento', 'order' => 1, 'requires_floor' => true],
            ['name' => 'Local comercial', 'order' => 2],
        ],
        UnitStatus::class => [
            ['name' => 'Activa', 'color' => '#22C55E', 'order' => 0],
            ['name' => 'Vacante', 'color' => '#3B82F6', 'order' => 1],
            ['name' => 'En obra', 'color' => '#F59E0B', 'order' => 2],
            ['name' => 'Inactiva', 'color' => '#6B7280', 'order' => 3, 'is_billable' => false],
        ],
        EmployeePosition::class => [
            ['name' => 'Vigilante', 'order' => 0, 'grants_role' => 'security'],
            ['name' => 'Jardinero', 'order' => 1],
            ['name' => 'Limpieza', 'order' => 2],
            ['name' => 'Mantenimiento', 'order' => 3],
            ['name' => 'Administrador', 'order' => 4, 'grants_role' => 'administrator'],
            ['name' => 'Otro', 'order' => 5],
        ],
        PayFrequency::class => [
            ['name' => 'Quincenal', 'order' => 0],
            ['name' => 'Mensual', 'order' => 1],
            ['name' => 'Semanal', 'order' => 2],
        ],
        EmployeeShift::class => [
            ['name' => 'Diurno', 'order' => 0],
            ['name' => 'Nocturno', 'order' => 1],
            ['name' => 'Mixto', 'order' => 2],
        ],
        BankAccountPurpose::class => [
            ['name' => 'Operativa', 'order' => 0],
            ['name' => 'Fondo de reserva', 'order' => 1],
            ['name' => 'Nómina', 'order' => 2],
            ['name' => 'Caja chica', 'order' => 3],
            ['name' => 'Otro', 'order' => 4],
        ],
        ChargeConceptCategory::class => [
            ['name' => 'Ordinaria', 'order' => 0],
            ['name' => 'Extraordinaria', 'order' => 1],
            ['name' => 'Recargo', 'order' => 2],
            ['name' => 'Proyecto', 'order' => 4, 'key' => ChargeConceptCategory::KEY_PROJECT],
        ],
        CommunityProjectCategory::class => [
            ['name' => 'Obra', 'color' => '#F59E0B', 'order' => 0],
            ['name' => 'Evento', 'color' => '#EC4899', 'order' => 1],
            ['name' => 'Mejora', 'color' => '#3B82F6', 'order' => 2],
            ['name' => 'Donación', 'color' => '#22C55E', 'order' => 3],
            ['name' => 'Extraordinario', 'color' => '#8B5CF6', 'order' => 4],
        ],
        ProviderType::class => [
            ['name' => 'Persona física', 'order' => 0],
            ['name' => 'Persona moral', 'order' => 1],
        ],
        ProviderServiceType::class => [
            ['name' => 'Recolección de basura', 'order' => 0],
            ['name' => 'Servicios públicos', 'order' => 1],
            ['name' => 'Mantenimiento', 'order' => 2],
            ['name' => 'Jardinería', 'order' => 3],
            ['name' => 'Limpieza', 'order' => 4],
            ['name' => 'Insumos y suministros', 'order' => 5],
            ['name' => 'Servicios profesionales', 'order' => 6],
            ['name' => 'Otro', 'order' => 7],
        ],
        VisitorMotive::class => [
            ['name' => 'Social / familiar', 'order' => 0],
            ['name' => 'Trabajador', 'order' => 1],
            ['name' => 'Reparto / mensajería', 'order' => 2],
            ['name' => 'Profesional (médico, etc.)', 'order' => 3],
            ['name' => 'Otro', 'order' => 4],
        ],
        AnnouncementType::class => [
            ['name' => 'Aviso general', 'icon' => '📢', 'order' => 0, 'key' => AnnouncementType::KEY_GENERAL],
            ['name' => 'Asamblea', 'icon' => '📅', 'order' => 1],
            ['name' => 'Mantenimiento', 'icon' => '🔧', 'order' => 2],
            ['name' => 'Financiero', 'icon' => '💰', 'order' => 3, 'key' => AnnouncementType::KEY_FINANCIAL],
            ['name' => 'Seguridad', 'icon' => '🔒', 'order' => 4],
            ['name' => 'Social / Evento', 'icon' => '🎉', 'order' => 5],
        ],
        // Portería — Package (Module 15)
        PackageCarrier::class => [
            ['name' => 'DHL', 'order' => 0],
            ['name' => 'FedEx', 'order' => 1],
            ['name' => 'UPS', 'order' => 2],
            ['name' => 'Estafeta', 'order' => 3],
            ['name' => 'J&T Express', 'order' => 4],
            ['name' => 'Amazon Logistics', 'order' => 5],
            ['name' => 'MercadoEnvíos', 'order' => 6],
            ['name' => 'Otro', 'order' => 7],
        ],
        PackageType::class => [
            ['name' => 'Caja pequeña', 'order' => 0],
            ['name' => 'Caja grande', 'order' => 1],
            ['name' => 'Sobre / paquete plano', 'order' => 2],
            ['name' => 'Bolsa', 'order' => 3],
            ['name' => 'Otro', 'order' => 4],
        ],
        // Incidencias — Complaint
        ComplaintCategory::class => [
            ['name' => 'Convivencia entre vecinos', 'order' => 0],
            ['name' => 'Ruido', 'order' => 1],
            ['name' => 'Mascotas', 'order' => 2],
            ['name' => 'Estacionamiento', 'order' => 3],
            ['name' => 'Áreas comunes', 'order' => 4],
            ['name' => 'Incidencia del condominio', 'order' => 5],
            ['name' => 'Administración / Directiva', 'order' => 6],
            ['name' => 'Otro', 'order' => 7],
        ],
        ComplaintPriority::class => [
            ['name' => 'Baja', 'color' => '#22C55E', 'order' => 0],
            ['name' => 'Media', 'color' => '#EAB308', 'order' => 1],
            ['name' => 'Alta', 'color' => '#EF4444', 'order' => 2],
        ],
    ];

    public function run(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach (self::CATALOGS as $model => $rows) {
            foreach ($rows as $row) {
                $match = isset($row['key'])
                    ? ['key' => $row['key']]
                    : ['name' => $row['name']];

                // firstOrCreate: la carga inicial JAMÁS sobreescribe lo que el
                // admin del clúster ya personalizó (renombres, colores, orden).
                $model::firstOrCreate($match, $row + ['is_active' => true]);
            }
        }

        $this->seedDirectChargeConcepts();

        $this->seedPackageSettings();

        $this->seedComplaintSettings();
    }

    // =========================================================================
    // Cobranza — Cobro directo / cargo puntual (Module 4)
    // =========================================================================

    /**
     * Seed the "Servicios / Eventuales" concept category plus the single generic
     * technical "OTHER" concept the free-text ("Otro") direct-charge path needs.
     *
     * DOMAIN concepts (access card, hall reservation, fine, …) are NOT pre-seeded here:
     * each SOURCE module creates its own concept when it needs one (e.g. Amenities
     * auto-creates its hall-reservation concept). Only "OTHER" is seeded, matched by its
     * stable English `code` (idempotent). It is the server-side fallback resolved by
     * DirectChargeService::resolveOtherConceptId() when a staff member types a free-text
     * motive (stored on charge.description), so the NOT-NULL charge.charge_concept_id FK
     * is always satisfiable.
     */
    public function seedDirectChargeConcepts(): void
    {
        // Single, PROTECTED service category (HasCatalogKey): resolvable by (tenant, key),
        // cannot be deleted and its key is immutable, so /charge/direct never loses its home.
        $category = ChargeConceptCategory::firstOrCreate(
            ['name' => 'Servicios / Eventuales'],
            ['name' => 'Servicios / Eventuales', 'order' => 5, 'key' => ChargeConceptCategory::KEY_SERVICES, 'is_active' => true],
        );

        // Backfill: a category seeded before it carried a key (tenants created 2026-07-03..now)
        // gets protected here. The HasCatalogKey `updating` guard makes key immutable, so
        // saveQuietly() (no model events) is the ONLY way to stamp it after creation.
        if ($category->key !== ChargeConceptCategory::KEY_SERVICES) {
            $category->key = ChargeConceptCategory::KEY_SERVICES;
            $category->saveQuietly();
        }

        // ONLY the generic technical fallback. firstOrCreate keeps an admin rename of the
        // Spanish `name` intact and makes re-running (seeder + backfill) safe.
        ChargeConcept::firstOrCreate(
            ['code' => 'OTHER'],
            ['code' => 'OTHER', 'name' => 'Otro / Concepto vario', 'charge_concept_category_id' => $category->id, 'is_active' => true],
        );
    }

    // =========================================================================
    // Portería — Package settings (Module 15)
    // =========================================================================

    /**
     * Seed the per-tenant Settings that govern package-intake behavior.
     * Uses firstOrCreate so the admin's customized values are never overwritten.
     *
     * Canonical slugs defined in package-policies spec (tasks WU-1.8.7 + WU-2):
     * auto_notify_on_arrival, pickup_code_enabled, requires_pickup_signature,
     * requires_pickup_evidence, reminder_after_days, return_after_days, liability_disclaimer.
     */
    private function seedPackageSettings(): void
    {
        $defaults = [
            [
                'slug' => 'package.auto_notify_on_arrival',
                'name' => 'Notificar al residente al recibir un paquete',
                'description' => 'Envía una notificación push al llegar un paquete para la unidad',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'package.pickup_code_enabled',
                'name' => 'Generar código de retiro',
                'description' => 'Genera un código alfanumérico único que el residente debe mostrar para retirar el paquete',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'package.requires_pickup_signature',
                'name' => 'Firma de retiro obligatoria',
                'description' => 'Exige capturar la firma digital del residente al entregar el paquete',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'package.requires_pickup_evidence',
                'name' => 'Evidencia fotográfica obligatoria al retirar',
                'description' => 'Exige adjuntar una foto o PDF como evidencia de que el paquete fue retirado',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'package.reminder_after_days',
                'name' => 'Días antes de enviar recordatorio',
                'description' => 'Número de días desde la recepción tras los cuales se envía un recordatorio de retiro',
                'value' => 3,
                'type' => Setting::TYPE_INTEGER,
            ],
            [
                'slug' => 'package.return_after_days',
                'name' => 'Días antes de devolver el paquete',
                'description' => 'Número de días desde la recepción tras los cuales el paquete se devuelve al remitente automáticamente',
                'value' => 10,
                'type' => Setting::TYPE_INTEGER,
            ],
            [
                'slug' => 'package.liability_disclaimer',
                'name' => 'Aviso de responsabilidad',
                'description' => 'Texto legal mostrado a los residentes sobre los límites de responsabilidad del condominio',
                'value' => 'El condominio no se responsabiliza por el deterioro de paquetes no retirados en el plazo establecido.',
                'type' => Setting::TYPE_STRING,
            ],
            [
                'slug' => 'package.require_pickup_code',
                'name' => 'Exigir código de retiro en la entrega',
                'description' => 'Cuando está activo, el guardia debe ingresar o escanear el código de retiro antes de entregar el paquete',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ],
        ];

        foreach ($defaults as $row) {
            Setting::firstOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }

    // =========================================================================
    // Incidencias — Complaint settings (module-config, complaints design §10)
    // =========================================================================

    /**
     * Seed the per-tenant Settings that govern complaint behavior.
     * Uses firstOrCreate so the admin's customized values are never overwritten.
     *
     * Defaults MUST stay in sync with ComplaintService's fallback constants
     * and ConfigurationController::complaintSettingDefaults(). The role-key
     * arrays list Kivo7 role KEYS (resolved to Spatie roles by the service).
     */
    private function seedComplaintSettings(): void
    {
        $defaults = [
            [
                'slug' => 'complaint.manager_role_keys',
                'name' => 'Roles que gestionan incidencias',
                'description' => 'Roles del condominio que pueden atender, asignar y cambiar el estado de las incidencias',
                'value' => ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'],
                'type' => Setting::TYPE_JSON,
            ],
            [
                'slug' => 'complaint.reporter_role_keys',
                'name' => 'Roles que pueden registrar incidencias',
                'description' => 'Roles del condominio autorizados para registrar nuevas incidencias',
                'value' => ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'],
                'type' => Setting::TYPE_JSON,
            ],
            [
                'slug' => 'complaint.auto_notify_managers_on_create',
                'name' => 'Avisar a gestores al registrar una incidencia',
                'description' => 'Envía una notificación a los roles gestores cuando se registra una nueva incidencia',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'complaint.requires_evidence',
                'name' => 'Exigir evidencia al registrar',
                'description' => 'Exige adjuntar al menos un archivo de evidencia para poder registrar la incidencia',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
            ],
            [
                'slug' => 'complaint.resolution_days_target',
                'name' => 'Días objetivo de resolución',
                'description' => 'Meta de días para resolver una incidencia; solo se usa como indicador visual de antigüedad',
                'value' => 15,
                'type' => Setting::TYPE_INTEGER,
            ],
            [
                'slug' => 'complaint.post_to_reports_channel',
                'name' => 'Publicar aviso en el canal Reportes y sugerencias',
                'description' => 'Publica un aviso (folio y categoría, sin detalles) en el canal Reportes y sugerencias, visible para toda la comunidad, al registrar una incidencia',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ],
        ];

        foreach ($defaults as $row) {
            Setting::firstOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
