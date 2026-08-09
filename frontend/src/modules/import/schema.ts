// Import module — interfaces only (no Zod form schema needed; file input is not RHF)

export type ImportRunStatus =
  | 'pending'
  | 'processing'
  | 'ready'
  | 'completed_with_errors'
  | 'failed'

/** Phase of the import run, mirrored in progress.phase by the backend. */
export type ImportPhase = 'reading_file' | 'processing' | 'finalizing' | 'done'

export type IssueSeverity = 'error' | 'warning'

/** A single per-row issue returned by validate or stored in the execution report. */
export interface IIssueRow {
  row: number
  severity: IssueSeverity
  column: string
  message: string
  /** True only for collapsed catalog-reference issues (see ImportRunner::collapseGeneralIssues). */
  general?: boolean
  /** Number of rows this issue applies to. Present only when `general`; absent/1 otherwise. */
  count?: number
}

/** Response from POST /import/validate (dry-run). */
export interface IValidateResponse {
  total: number
  units_to_create: number
  residents_to_create: number
  payments_to_register: number
  blocked: number
  /** Per-row issues (errors and warnings). */
  rows: IIssueRow[]
  /** True when the file exceeded the backend's validate_row_cap — only the first `row_cap` rows were validated. */
  truncated?: boolean
  /** The row cap applied when `truncated` is true. */
  row_cap?: number
  /** Backend-authored, user-facing notice describing the truncation — render verbatim. */
  preview_message?: string
}

/** Progress snapshot stored in import_run.progress. */
export interface IImportProgress {
  processed: number
  total: number
  /** Current phase of the run — undefined for runs created before this field was added. */
  phase?: ImportPhase
}

/** Per-account balance delta in the final report. */
export interface IBankBalanceDelta {
  bank_account: string
  delta: number
}

/** Final execution report stored in import_run.report. */
export interface IImportReport {
  total: number
  units_created: number
  residents_created: number
  payments_registered: number
  payments_skipped: number
  /**
   * Rows that threw an unexpected error during execute().
   * Idempotency skips (duplicate folios) are payments_skipped, not failed_count.
   */
  failed_count: number
  /**
   * Equal to payments_registered; kept explicit so the banner can read
   * "X registrados, Y con error" without recomputing.
   */
  succeeded_count: number
  bank_balance_delta: IBankBalanceDelta[]
  /** Per-row issues from the execution run (warnings + errors for blocked rows). */
  rows: IIssueRow[]
}

/** ImportRun resource returned by GET /import/{id}/status. */
export interface IImportRun {
  id: string
  status: ImportRunStatus
  /** Top-level phase mirror — optional for back-compat with pre-migration runs. */
  phase?: ImportPhase
  file_path: string | null
  progress: IImportProgress | null
  report: IImportReport | null
  error: string | null
  created_at?: string
  updated_at?: string
}
