import type { useConfiguration } from '@/modules/configuration/hooks/useConfiguration'
import { CollectionConfigSection } from '@/modules/configuration/components/CollectionConfigSection'
import { ComplaintConfigSection } from '@/modules/configuration/components/ComplaintConfigSection'
import { DelinquencyConfigSection } from '@/modules/configuration/components/DelinquencyConfigSection'
import { GeneralConfigSection } from '@/modules/configuration/components/GeneralConfigSection'
import { LegalDocumentsSection } from '@/modules/configuration/components/LegalDocumentsSection'
import { NotificationTemplatesSection } from '@/modules/configuration/components/NotificationTemplatesSection'
import { NotificationsConfigSection } from '@/modules/configuration/components/NotificationsConfigSection'
import { OperationalConfigSection } from '@/modules/configuration/components/OperationalConfigSection'
import { PackageConfigSection } from '@/modules/configuration/components/PackageConfigSection'
import { PlanSummarySection } from '@/modules/configuration/components/PlanSummarySection'
import {
  Bell,
  Building2,
  CreditCard,
  FileText,
  MessageSquare,
  Package2,
  Settings2,
  ShieldAlert,
  ShieldBan,
  Wallet,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

/**
 * Bag de datos + mutations que expone useConfiguration(). Las secciones "legacy"
 * reciben de acá lo que necesitan; las secciones nuevas AUTOCONTENIDAS (patrón
 * PackageConfigSection, que se abastece solo vía React Query) sólo usan canUpdate.
 */
type ConfigurationBag = ReturnType<typeof useConfiguration>

export interface ConfigSectionContext {
  canUpdate: boolean
  config: ConfigurationBag
}

/**
 * Definición de UNA pestaña de la Configuración del condominio.
 *
 * REGISTRO ÚNICO — incluir un módulo en la Configuración del tenant = agregar UNA
 * entrada a `configSections`. ConfigurationPage recorre este array y arma el tab,
 * el SectionIntro y el cuerpo solo. Ya no hay 3 ediciones dispersas (import +
 * TabsTrigger + TabsContent) por módulo.
 *
 * Para una sección nueva autocontenida (lo recomendado, patrón PackageConfigSection):
 *   render: ({ canUpdate }) => <MiModuloConfigSection canUpdate={canUpdate} />
 */
export interface ConfigSectionDef {
  /** key estable del tab; se persiste en ?tab=<key> para deep-links */
  key: string
  /** etiqueta visible del tab (ES) */
  label: string
  /** ícono lucide para el encabezado (SectionIntro) */
  icon: LucideIcon
  /** título del SectionIntro */
  title: string
  /** descripción del SectionIntro */
  description: string
  /** oculta el tab sin borrar la entrada (feature flag); default: visible */
  hidden?: boolean
  /** cuerpo de la pestaña; recibe canUpdate + el bag de useConfiguration */
  render: (ctx: ConfigSectionContext) => ReactNode
}

/**
 * Pestaña "Plantillas" oculta TEMPORALMENTE. El texto editado aplica a in-app y
 * push (+ asunto del email), pero el CUERPO del email viene del diseño de React
 * Email, no de este editor — hasta reconciliar eso, no exponemos la edición para
 * no dar una falsa sensación de control. Poner en `true` para reactivarla.
 */
const SHOW_NOTIFICATION_TEMPLATES = false

export const configSections: ConfigSectionDef[] = [
  {
    key: 'general',
    label: 'General',
    icon: Building2,
    title: 'Identidad del condominio',
    description:
      'Datos legales y de marca de tu condominio. La razón social, el RFC y el domicilio fiscal aparecen en los recibos y documentos oficiales; el logo identifica a tu condominio en el menú de la app.',
    render: ({ canUpdate, config }) => (
      <GeneralConfigSection
        general={config.general}
        canUpdate={canUpdate}
        saveMutation={config.saveMutation}
        logoMutation={config.logoMutation}
      />
    ),
  },
  {
    key: 'operational',
    label: 'Operación',
    icon: Settings2,
    title: 'Parámetros de operación',
    description:
      'Cómo funciona la plataforma día a día: zona horaria y moneda (base de todos los montos y fechas) y tipo de condominio.',
    render: ({ canUpdate, config }) => (
      <OperationalConfigSection
        operational={config.operational}
        canUpdate={canUpdate}
        saveMutation={config.saveMutation}
      />
    ),
  },
  {
    key: 'collections',
    label: 'Cobranza',
    icon: Wallet,
    title: 'Reglas de cobranza',
    description:
      "Controla cuándo se generan los cargos y con cuánta anticipación se avisa 'por vencer'. Los días de gracia se definen en cada concepto de cobro. Conviene definirlo antes de generar tu primer lote de cargos.",
    render: ({ canUpdate, config }) => (
      <CollectionConfigSection
        collection={config.collection}
        canUpdate={canUpdate}
        saveMutation={config.saveMutation}
      />
    ),
  },
  {
    key: 'packages',
    label: 'Paquetería',
    icon: Package2,
    title: 'Paquetería',
    description:
      'Configura cómo el condominio gestiona los paquetes en portería: notificaciones de llegada, código de retiro QR, firma y evidencia requeridas, y tiempos de retención antes del recordatorio y devolución.',
    render: ({ canUpdate }) => <PackageConfigSection canUpdate={canUpdate} />,
  },
  {
    key: 'complaints',
    label: 'Incidencias',
    icon: ShieldAlert,
    title: 'Incidencias',
    description:
      'Configura cómo funciona el módulo de incidencias: qué roles pueden registrarlas y gestionarlas, si se exige evidencia al registrar, los avisos a gestores y la meta de días de resolución.',
    render: ({ canUpdate }) => <ComplaintConfigSection canUpdate={canUpdate} />,
  },
  {
    key: 'delinquency',
    label: 'Morosidad',
    icon: ShieldBan,
    title: 'Restricciones por morosidad',
    description:
      'Restringe funciones a las unidades con adeudos vencidos: define desde qué monto aplica y qué funciones se bloquean (reservas de amenidades, accesos de visita, recepción de paquetes).',
    render: ({ canUpdate }) => <DelinquencyConfigSection canUpdate={canUpdate} />,
  },
  {
    key: 'templates',
    label: 'Plantillas',
    icon: MessageSquare,
    title: 'Plantillas de notificación',
    description:
      'Los mensajes automáticos que reciben tus residentes (recordatorios de pago, confirmaciones, avisos). Personaliza el texto o restáuralo a los valores por defecto.',
    hidden: !SHOW_NOTIFICATION_TEMPLATES,
    render: ({ canUpdate, config }) => (
      <NotificationTemplatesSection
        templates={config.templates}
        canUpdate={canUpdate}
        templatesMutation={config.templatesMutation}
        restoreTemplatesMutation={config.restoreTemplatesMutation}
      />
    ),
  },
  {
    key: 'notifications',
    label: 'Notificaciones',
    icon: Bell,
    title: 'Notificaciones del condominio',
    description:
      'Controla qué eventos se notifican, por qué canales (email, push, WhatsApp) y a qué roles de la mesa directiva llegan los avisos de operación.',
    render: ({ canUpdate, config }) => (
      <NotificationsConfigSection
        events={config.notificationConfig?.events ?? []}
        boardRoles={config.notificationConfig?.board_roles ?? []}
        canUpdate={canUpdate}
        isError={config.notificationsError}
        isLoading={config.notificationsLoading}
        notificationsMutation={config.notificationsMutation}
      />
    ),
  },
  {
    key: 'legal',
    label: 'Legal',
    icon: FileText,
    title: 'Documentos legales',
    description:
      'Documentos oficiales del condominio (reglamento, acta constitutiva, aviso de privacidad). Súbelos en PDF o imagen y decide cuáles pueden ver los residentes desde su app.',
    render: ({ canUpdate, config }) => (
      <LegalDocumentsSection
        legalDocuments={config.legalDocuments}
        canUpdate={canUpdate}
        uploadLegalMutation={config.uploadLegalMutation}
      />
    ),
  },
  {
    key: 'plan',
    label: 'Plan',
    icon: CreditCard,
    title: 'Plan y suscripción',
    description:
      'Tu suscripción a Kivo7 y el consumo de unidades frente al límite de tu plan. Esta sección es informativa (solo lectura).',
    render: ({ config }) => <PlanSummarySection plan={config.plan} />,
  },
]
