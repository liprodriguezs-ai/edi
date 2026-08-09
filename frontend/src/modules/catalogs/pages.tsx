import { createCatalogPage, createSimpleCatalogPage } from '@/modules/catalogs/createCatalogPage'

/**
 * Páginas CRUD de los catálogos autoadministrables del organización (tenant-scoped).
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
  description: 'Propósitos de las cuentas del organización (operativa, reserva…)',
  itemLabel: 'propósito',
  entity: 'bank_account_purpose',
})

export const ChargeConceptCategoryPage = createCatalogPage({
  title: 'Categorías de Concepto',
  description: 'Categorías de los conceptos de cobro (ordinaria, servicio…)',
  itemLabel: 'categoría',
  entity: 'charge_concept_category',
})

export const CommunityProjectCategoryPage = createCatalogPage({
  title: 'Categorías de Proyecto',
  description: 'Categorías de los proyectos comunitarios (obra, evento…)',
  itemLabel: 'categoría',
  entity: 'community_project_category',
  withColor: true,
})

export const ProviderTypePage = createCatalogPage({
  title: 'Tipos de Proveedor',
  description: 'Tipos de proveedor del organización (persona física, moral…)',
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
  description: 'Tipos de aviso del organización (asamblea, mantenimiento…)',
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
  entity: 'education_level',
})

export const OccupationPage = createSimpleCatalogPage({
  title: 'Ocupaciones',
  description: 'Ocupaciones de los cuidadores',
  itemLabel: 'ocupación',
  entity: 'occupation',
})
