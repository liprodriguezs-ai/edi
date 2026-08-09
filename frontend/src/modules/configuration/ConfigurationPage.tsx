import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { SectionIntro } from '@/modules/configuration/components/SectionIntro'
import { configSections } from '@/modules/configuration/configSections'
import { useConfiguration } from '@/modules/configuration/hooks/useConfiguration'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useSearchParams } from 'react-router-dom'

/**
 * Configuración del condominio (§16): shell de tabs que delega cada sección a su
 * componente. Las pestañas se arman recorriendo el registro `configSections`
 * (una entrada por módulo), así incluir un módulo nuevo en la Configuración del
 * tenant es agregar UNA entrada, no editar este archivo.
 *
 * Datos y mutations vienen de useConfiguration() y se inyectan a cada sección vía
 * render(ctx). canUpdate controla la interactividad.
 *
 * El tab activo se persiste en el query param ?tab=<key> para permitir deep-links
 * y que el refresh mantenga la pestaña seleccionada.
 */
export const ConfigurationPage = () => {
  const canUpdate = usePermissions('update.configuration')
  const config = useConfiguration()

  const sections = configSections.filter((section) => !section.hidden)

  const [searchParams, setSearchParams] = useSearchParams()
  const tabParam = searchParams.get('tab') ?? ''
  const activeTab = sections.some((section) => section.key === tabParam)
    ? tabParam
    : sections[0].key

  const handleTabChange = (value: string) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.set('tab', value)
        return next
      },
      { replace: true },
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Configuración" description="Identidad, operación y parámetros del condominio" />

      <Tabs value={activeTab} onValueChange={handleTabChange}>
        <TabsList>
          {sections.map((section) => (
            <TabsTrigger key={section.key} value={section.key}>
              {section.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {sections.map((section) => (
          <TabsContent key={section.key} value={section.key} className="mt-4">
            <SectionIntro icon={section.icon} title={section.title} description={section.description} />
            {section.render({ canUpdate, config })}
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}
