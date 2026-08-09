<?php

return [
    'name' => 'DataImport',

    /*
     * Maximum number of DATA rows materialized from the uploaded workbook
     * during the synchronous POST /import/validate dry-run preview. Bounds
     * worker memory on the shared Octane process (plan 059). Does NOT apply
     * to EXECUTE, which reads the full file via a separate queued + chunked
     * pipeline (RunImportJob -> ProcessImportChunkJob, 200 rows/job).
     */
    'validate_row_cap' => (int) env('DATA_IMPORT_VALIDATE_ROW_CAP', 1000),
];
