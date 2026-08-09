/**
 * Importación de colaboradores desde CSV (§7.2). Parser sin dependencias y
 * mapeo de columnas a las claves que espera el backend (validate_import).
 */

/** Fila ya validada por el backend (POST /collaborator/validate_import). */
export interface CollaboratorImportValidation {
  row_index: number
  data: Record<string, unknown>
  has_errors: boolean
  errors: string[]
}

/** Resultado del alta por lote (POST /collaborator/batch_import). */
export interface CollaboratorBatchImportResult {
  imported_count: number
  failed_count: number
  failed: Array<{ row_index: number; data: Record<string, unknown>; error: string }>
}

/** Campos importables, en el orden de la plantilla. `label` es el encabezado en español. */
export const COLLABORATOR_IMPORT_FIELDS: ReadonlyArray<{ key: string; label: string }> = [
  { key: 'name', label: 'Nombre' },
  { key: 'last_name', label: 'Primer apellido' },
  { key: 'second_last_name', label: 'Segundo apellido' },
  { key: 'curp', label: 'CURP' },
  { key: 'rfc', label: 'RFC' },
  { key: 'nss', label: 'NSS' },
  { key: 'email', label: 'Email' },
  { key: 'phone', label: 'Teléfono' },
  { key: 'date_of_birth', label: 'Fecha de nacimiento' },
  { key: 'contract_at', label: 'Fecha de contrato' },
  { key: 'contracted', label: 'Contratado' },
  { key: 'marital_status', label: 'Estado civil' },
  { key: 'nationality', label: 'Nacionalidad' },
  { key: 'gender', label: 'Género' },
]

/** Quita acentos y normaliza un encabezado para comparar de forma laxa. */
function normalizeHeader(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
}

/** Mapa de encabezado normalizado (clave o etiqueta) → clave del backend. */
const HEADER_TO_KEY: Record<string, string> = COLLABORATOR_IMPORT_FIELDS.reduce(
  (acc, { key, label }) => {
    acc[normalizeHeader(key)] = key
    acc[normalizeHeader(label)] = key
    return acc
  },
  {} as Record<string, string>,
)

/**
 * Parser CSV mínimo: respeta comillas dobles, comillas escapadas ("") y CRLF.
 * Devuelve una matriz de celdas string; descarta filas totalmente vacías.
 */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = []
  let field = ''
  let row: string[] = []
  let inQuotes = false

  const pushField = () => { row.push(field); field = '' }
  const pushRow = () => { pushField(); rows.push(row); row = [] }

  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i]
    if (inQuotes) {
      if (ch === '"' && text[i + 1] === '"') { field += '"'; i += 1 }
      else if (ch === '"') { inQuotes = false }
      else { field += ch }
    } else if (ch === '"') {
      inQuotes = true
    } else if (ch === ',') {
      pushField()
    } else if (ch === '\n') {
      pushRow()
    } else if (ch !== '\r') {
      field += ch
    }
  }
  if (field.length > 0 || row.length > 0) pushRow()

  return rows.filter((cells) => cells.some((cell) => cell.trim() !== ''))
}

/** Convierte el texto CSV en filas-objeto con las claves del backend. */
export function csvToImportRows(text: string): Array<Record<string, string>> {
  const matrix = parseCsv(text)
  if (matrix.length < 2) return []

  const keys = matrix[0].map((header) => HEADER_TO_KEY[normalizeHeader(header)] ?? null)

  return matrix.slice(1).map((cells) => {
    const rowObject: Record<string, string> = {}
    keys.forEach((key, index) => {
      const value = cells[index]?.trim()
      if (key && value) rowObject[key] = value
    })
    return rowObject
  })
}

/** Contenido de la plantilla CSV (encabezados + una fila de ejemplo). */
export function buildTemplateCsv(): string {
  const headers = COLLABORATOR_IMPORT_FIELDS.map((field) => field.label)
  const example = [
    'Juan', 'Pérez', 'García', 'PEGJ900101HDFRRN09', 'PEGJ900101AB1',
    '12345678901', 'juan.perez@ejemplo.com', '5544332211', '1990-01-01',
    '2024-06-01', 'true', 'Soltero', 'Mexicana', 'Masculino',
  ]
  return `${headers.join(',')}\n${example.join(',')}\n`
}
