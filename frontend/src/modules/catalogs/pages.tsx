import { createCatalogPage, createSimpleCatalogPage } from '@/modules/catalogs/createCatalogPage'

/**
 * Páginas CRUD de los catálogos autoadministrables de la organización (tenant-scoped).
 * Cada organización ve y edita SOLO sus filas; las filas de sistema (candado) las
 * usan flujos automáticos y no pueden eliminarse.
 */

export const EmployeePositionPage = createCatalogPage({
  title: 'Puestos de Empleado',
  description: 'Puestos del personal de tu organización (vigilante, jardinero…)',
  itemLabel: 'puesto',
  entity: 'employee_position',
})

export const PayFrequencyPage = createCatalogPage({
  title: 'Frecuencias de Pago',
  description: 'Frecuencias de pago del personal (semanal, quincenal…)',
  itemLabel: 'frecuencia',
  itemGender: 'f',
  entity: 'pay_frequency',
})

export const EmployeeShiftPage = createCatalogPage({
  title: 'Turnos de Empleado',
  description: 'Turnos del personal de tu organización (diurno, nocturno…)',
  itemLabel: 'turno',
  entity: 'employee_shift',
})

export const BankAccountPurposePage = createCatalogPage({
  title: 'Propósitos de Cuenta',
  description: 'Propósitos de las cuentas de la organización (operativa, reserva…)',
  itemLabel: 'propósito',
  entity: 'bank_account_purpose',
})

export const ChargeConceptCategoryPage = createCatalogPage({
  title: 'Categorías de Concepto',
  description: 'Categorías de los conceptos de cobro (ordinaria, servicio…)',
  itemLabel: 'categoría',
  itemGender: 'f',
  entity: 'charge_concept_category',
})

export const CommunityProjectCategoryPage = createCatalogPage({
  title: 'Categorías de Proyecto',
  description: 'Categorías de los proyectos comunitarios (obra, evento…)',
  itemLabel: 'categoría',
  itemGender: 'f',
  entity: 'community_project_category',
  withColor: true,
})

export const ProviderTypePage = createCatalogPage({
  title: 'Tipos de Proveedor',
  description: 'Tipos de proveedor de la organización (persona física, moral…)',
  itemLabel: 'tipo',
  entity: 'provider_type',
})

export const ProviderServiceTypePage = createCatalogPage({
  title: 'Tipos de Servicio',
  description: 'Servicios que prestan tus proveedores (jardinería, limpieza…)',
  itemLabel: 'tipo de servicio',
  entity: 'provider_service_type',
})

export const VisitorMotivePage = createCatalogPage({
  title: 'Motivos de Visita',
  description: 'Motivos de visita en el control de accesos (social, reparto…)',
  itemLabel: 'motivo',
  entity: 'visitor_motive',
})

export const AnnouncementTypePage = createCatalogPage({
  title: 'Tipos de Aviso',
  description: 'Tipos de aviso del centro (jornada de tamizaje, capacitación…)',
  itemLabel: 'tipo de aviso',
  entity: 'announcement_type',
  withIcon: true,
})

// ChildRecord (edi-clinic-and-child-registry) — catálogos tenant-scoped SIN
// columna `order` (design.md §3.2).
export const CaregiverRelationshipPage = createSimpleCatalogPage({
  title: 'Parentescos',
  description: 'Parentescos entre un niño y sus cuidadores (madre, padre, tutor legal…)',
  itemLabel: 'parentesco',
  entity: 'caregiver_relationship',
})

export const EducationLevelPage = createSimpleCatalogPage({
  title: 'Escolaridades',
  description: 'Niveles de escolaridad de los cuidadores',
  itemLabel: 'escolaridad',
  itemGender: 'f',
  entity: 'education_level',
})

export const OccupationPage = createSimpleCatalogPage({
  title: 'Ocupaciones',
  description: 'Ocupaciones de los cuidadores',
  itemLabel: 'ocupación',
  itemGender: 'f',
  entity: 'occupation',
})

// Stimulation (edi-stimulation-sessions) — catálogos tenant-scoped SIN
// columna `order` (design.md §3: name/description/is_active únicamente).
export const StimulationDynamicPage = createSimpleCatalogPage({
  title: 'Dinámicas de Estimulación',
  description: 'Dinámicas usadas en las secciones de integración y actividad central',
  itemLabel: 'dinámica',
  itemGender: 'f',
  entity: 'stimulation_dynamic',
})

export const BodyZonePage = createSimpleCatalogPage({
  title: 'Zonas Corporales',
  description: 'Zonas corporales usadas en el masaje y la relajación',
  itemLabel: 'zona corporal',
  itemGender: 'f',
  entity: 'body_zone',
})

export const MassageMovementPage = createSimpleCatalogPage({
  title: 'Movimientos de Masaje',
  description: 'Movimientos aplicados a una zona corporal en la sección de preparación',
  itemLabel: 'movimiento',
  entity: 'massage_movement',
})

export const DidacticMaterialPage = createSimpleCatalogPage({
  title: 'Materiales Didácticos',
  description: 'Sugerencias de materiales para la sesión (texto libre en la sesión)',
  itemLabel: 'material',
  entity: 'didactic_material',
})

export const SongPage = createSimpleCatalogPage({
  title: 'Canciones',
  description: 'Sugerencias de canciones para la sesión (texto libre en la sesión)',
  itemLabel: 'canción',
  itemGender: 'f',
  entity: 'song',
})

export const StimulationGroupTypePage = createSimpleCatalogPage({
  title: 'Tipos de Grupo',
  description: 'Tipos de grupo de estimulación (grupo abierto…)',
  itemLabel: 'tipo de grupo',
  entity: 'stimulation_group_type',
})

// FollowUp (edi-followup-and-referral)
export const ReferralSpecialtyPage = createSimpleCatalogPage({
  title: 'Especialidades de Referencia',
  description: 'Especialidades sugeridas al registrar una referencia a especialista',
  itemLabel: 'especialidad',
  itemGender: 'f',
  entity: 'referral_specialty',
})
