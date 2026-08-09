import { IconFileImport } from '@tabler/icons-react'

/**
 * ImportIntro — header and description shown at the top of the import wizard.
 * Always visible (Section 0).
 */
export function ImportIntro() {
  return (
    <div className="flex items-start gap-4">
      <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
        <IconFileImport className="h-6 w-6" />
      </div>
      <div>
        <h2 className="text-xl font-semibold">Importar pagos desde Excel</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Carga un archivo .xlsx con los pagos a registrar. El sistema validará cada fila
          antes de ejecutar, sin modificar nada en la base de datos hasta que confirmes.
          Los pagos se aplican con lógica FIFO sobre los cargos abiertos de cada unidad.
        </p>
      </div>
    </div>
  )
}
