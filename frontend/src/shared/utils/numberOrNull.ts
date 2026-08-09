/**
 * Coacciona un input numérico vacío (string vacío o null/undefined) a `null`, y
 * cualquier otro valor a `number`. Pensado para
 * `register(name, { setValueAs: numberOrNull })` en inputs `type='number'` cuyo
 * schema Zod acepta `number | null` (mismo patrón que los montos del form).
 */
export function numberOrNull(value: unknown): number | null {
  return value === '' || value == null ? null : Number(value)
}
