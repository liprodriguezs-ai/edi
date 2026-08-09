import { Button } from '@/components/ui/button'
import { CatalogChecklist } from '@/modules/import/components/CatalogChecklist'
import { ConfirmStep } from '@/modules/import/components/ConfirmStep'
import { ImportIntro } from '@/modules/import/components/ImportIntro'
import { ImportManual } from '@/modules/import/components/ImportManual'
import { ImportReport } from '@/modules/import/components/ImportReport'
import { UploadStep } from '@/modules/import/components/UploadStep'
import { ValidateStep } from '@/modules/import/components/ValidateStep'
import { ImportErrorDialog } from '@/modules/import/components/ImportErrorDialog'
import { ImportResource } from '@/modules/import/services/ImportResource'
import type { IImportReport, IValidateResponse } from '@/modules/import/schema'
import {
  IconArrowLeft,
  IconCheck,
  IconCloudDownload,
  IconFileImport,
  IconFileSearch,
  IconLoader2,
  IconReportAnalytics,
  IconRocket,
} from '@tabler/icons-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

const STEPS = [
  { title: 'Subir archivo', description: 'Selecciona el .xlsx', icon: IconFileImport },
  { title: 'Validar', description: 'Revisión de errores', icon: IconFileSearch },
  { title: 'Ejecutar', description: 'Confirmar y procesar', icon: IconRocket },
  { title: 'Resultado', description: 'Reporte final', icon: IconReportAnalytics },
] as const

/**
 * ImportPage — self-contained stepper wizard for importing payments from Excel.
 *
 * Section 0 (always visible): ImportIntro + ImportManual + CatalogChecklist + template download.
 * Step 1: UploadStep (file selection + validate mutation).
 * Step 2: ValidateStep (dry-run results review).
 * Step 3: ConfirmStep (execute + polling) — implemented in Slice 3.
 */
export function ImportPage() {
  const navigate = useNavigate()
  const [currentStep, setCurrentStep] = useState(0)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [validateResponse, setValidateResponse] = useState<IValidateResponse | null>(null)
  const [importReport, setImportReport] = useState<IImportReport | null>(null)
  const [isDownloading, setIsDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  async function handleDownloadTemplate() {
    setIsDownloading(true)
    try {
      await ImportResource.downloadTemplate()
    } catch {
      setDownloadError('No se pudo descargar el template. Intenta de nuevo.')
    } finally {
      setIsDownloading(false)
    }
  }

  function handleValidated(file: File, response: IValidateResponse) {
    setSelectedFile(file)
    setValidateResponse(response)
    setCurrentStep(1)
  }

  function handleContinueToExecute() {
    setCurrentStep(2)
  }

  function handleBackToUpload() {
    setSelectedFile(null)
    setValidateResponse(null)
    setCurrentStep(0)
  }

  function handleImportComplete(report: IImportReport) {
    setImportReport(report)
    setCurrentStep(3)
  }

  return (
    <div className="space-y-8">
      {/* Page header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
          <IconArrowLeft className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Importar pagos</h1>
          <p className="text-muted-foreground text-sm">Carga masiva desde archivo Excel</p>
        </div>
      </div>

      {/* Section 0 — always visible */}
      <section className="rounded-xl border bg-card p-6 space-y-6">
        <ImportIntro />
        <div className="grid gap-6 md:grid-cols-2">
          <ImportManual />
          <div className="space-y-4">
            <CatalogChecklist />
            <Button
              variant="outline"
              size="sm"
              onClick={handleDownloadTemplate}
              disabled={isDownloading}
              className="w-full"
            >
              {isDownloading ? (
                <>
                  <IconLoader2 className="mr-2 h-4 w-4 animate-spin" />
                  Descargando…
                </>
              ) : (
                <>
                  <IconCloudDownload className="mr-2 h-4 w-4" />
                  Descargar template
                </>
              )}
            </Button>
          </div>
        </div>
      </section>

      {/* Stepper nav */}
      <nav aria-label="Progreso de importación" className="mx-auto max-w-lg">
        <ol className="flex items-center">
          {STEPS.map((step, index) => {
            const isCompleted = index < currentStep
            const isCurrent = index === currentStep
            const StepIcon = step.icon

            return (
              <li
                key={step.title}
                className={`flex items-center ${index < STEPS.length - 1 ? 'flex-1' : ''}`}
              >
                <div className="flex flex-col items-center gap-2 text-center">
                  <div
                    className={`flex h-10 w-10 items-center justify-center rounded-full border-2 transition-colors ${
                      isCompleted
                        ? 'border-primary bg-primary text-primary-foreground'
                        : isCurrent
                          ? 'border-primary bg-background text-primary'
                          : 'border-muted-foreground/30 bg-background text-muted-foreground/50'
                    }`}
                  >
                    {isCompleted ? <IconCheck className="h-5 w-5" /> : <StepIcon className="h-5 w-5" />}
                  </div>
                  <div className="min-w-[100px]">
                    <p
                      className={`text-sm font-medium ${
                        isCurrent || isCompleted ? 'text-foreground' : 'text-muted-foreground/50'
                      }`}
                    >
                      {step.title}
                    </p>
                    <p className="hidden text-xs text-muted-foreground sm:block">{step.description}</p>
                  </div>
                </div>
                {index < STEPS.length - 1 && (
                  <div className="mx-4 mb-8 flex-1">
                    <div
                      className={`h-0.5 w-full transition-colors ${
                        isCompleted ? 'bg-primary' : 'bg-muted-foreground/20'
                      }`}
                    />
                  </div>
                )}
              </li>
            )
          })}
        </ol>
      </nav>

      {/* Step content */}
      <section className="rounded-xl border bg-card p-6">
        {currentStep === 0 && <UploadStep onValidated={handleValidated} />}

        {currentStep === 1 && validateResponse && (
          <ValidateStep
            response={validateResponse}
            onContinue={handleContinueToExecute}
            onBack={handleBackToUpload}
          />
        )}

        {currentStep === 2 && selectedFile && (
          <ConfirmStep file={selectedFile} onComplete={handleImportComplete} />
        )}

        {currentStep === 3 && importReport && (
          <ImportReport report={importReport} />
        )}
      </section>

      <ImportErrorDialog
        open={downloadError !== null}
        title="No se pudo descargar el template"
        message={downloadError ?? ''}
        onClose={() => setDownloadError(null)}
      />
    </div>
  )
}
