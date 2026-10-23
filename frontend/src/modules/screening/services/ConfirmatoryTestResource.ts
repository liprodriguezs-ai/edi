import type { ConfirmatoryTestFormValues, IConfirmatoryTest } from '@/modules/screening/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * REQ-SUI-010/REQ-SCR-014 — plain CRUD (index/show/store/update only, no
 * destroy). URL segment is snake_case (`confirmatory_test`), matching the
 * backend route registration (routes/api.php deliberate deviation note).
 */
export const ConfirmatoryTestResource = createCrudApi<IConfirmatoryTest, ConfirmatoryTestFormValues>({
  basePath: '/confirmatory_test',
})
