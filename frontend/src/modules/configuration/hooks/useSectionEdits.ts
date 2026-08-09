import { useState } from 'react'

/**
 * Encapsula el patrón overlay de ediciones locales por sección:
 *   form = { ...server, ...edits }
 *
 * Genérico para cualquier slice de configuración (GeneralConfig, OperationalConfig, etc.).
 * Las ediciones se aplican encima del valor del servidor sin mutarlo, y se pueden
 * descartar con `reset()` o parchearse campo a campo con `setField()`.
 */
export function useSectionEdits<T extends object>(server: T | undefined) {
  const [edits, setEdits] = useState<Partial<T>>({})

  const form: T = { ...(server as T), ...edits }

  function setField<K extends keyof T>(key: K, value: T[K]) {
    setEdits((prev) => ({ ...prev, [key]: value }))
  }

  function reset() {
    setEdits({})
  }

  return { form, edits, setField, reset }
}
