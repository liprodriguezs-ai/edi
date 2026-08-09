/**
 * ImportManual — 6-point usage manual for the import wizard (Section 0).
 * Text matches plan §6 exactly.
 */
export function ImportManual() {
  const steps = [
    {
      title: 'Descarga el template',
      description:
        'Usa el botón "Descargar template" para obtener el archivo Excel con la estructura correcta y las hojas de referencia precargadas con tus catálogos actuales.',
    },
    {
      title: 'Completa el archivo',
      description:
        'Llena la hoja "PAGOS" con los datos de cada pago. Los campos obligatorios son: Tipo de unidad, Número, Nombre, Apellido paterno, Fecha de pago y Monto. Consulta las hojas de referencia para los valores aceptados en cada catálogo.',
    },
    {
      title: 'Sube y valida',
      description:
        'Selecciona tu archivo en el Paso 1 y haz clic en "Validar". El sistema revisará todas las filas sin escribir nada en la base de datos. Los errores bloquean la importación; las advertencias no.',
    },
    {
      title: 'Revisa el resultado',
      description:
        'En el Paso 2 verás el resumen: cuántas unidades y residentes se crearán, cuántos pagos se registrarán y qué filas tienen problemas. Las filas con error aparecen en rojo; las advertencias en ámbar.',
    },
    {
      title: 'Confirma y ejecuta',
      description:
        'Si no hay errores, el botón "Continuar" se habilitará. En el Paso 3 revisa el resumen final y ejecuta la importación. El proceso corre en segundo plano y puedes ver el progreso en tiempo real.',
    },
    {
      title: 'Re-importación segura',
      description:
        'Puedes subir el mismo archivo varias veces sin riesgo de duplicados. El sistema usa el folio físico (o la combinación unidad + fecha + monto) para identificar pagos ya registrados y los omite automáticamente.',
    },
  ]

  return (
    <div>
      <h3 className="mb-3 text-sm font-semibold text-muted-foreground uppercase tracking-wide">
        Cómo usar esta herramienta
      </h3>
      <ol className="space-y-4">
        {steps.map((step, index) => (
          <li key={index} className="flex gap-3">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
              {index + 1}
            </span>
            <div>
              <p className="text-sm font-medium">{step.title}</p>
              <p className="text-sm text-muted-foreground">{step.description}</p>
            </div>
          </li>
        ))}
      </ol>
    </div>
  )
}
