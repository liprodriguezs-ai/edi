/**
 * Pure parameter builders for useBaseList.
 *
 * All functions here receive plain VALUES (no refs, no store) and return
 * plain objects — making them independently testable and side-effect free.
 */
import type { IPaginationMeta, ISearchParams } from '@/shared/interfaces/list.types'

// ─── Shared types (owned here, re-exported from useBaseList) ─────────────────

export interface OverrideParams {
    filters?: Array<{ field: string; operator: string; value: unknown }>
    sort?: Array<{ field: string; direction?: 'asc' | 'desc' }>
    includes?: Array<{ relation: string }>
    [key: string]: unknown
}

// ─── Internal sub-types ──────────────────────────────────────────────────────

interface StorePagination {
    page?: number
    limit?: number
    total?: number
    pages?: number
    per_page?: number
    to?: number | null
    from?: number | null
}

export interface PaginationResult {
    page: number
    limit: number
}

export interface PaginationUpdate {
    page: number
    limit: number
    total: number
    pages: number
    per_page: number
    from: number | null
    to: number | null
}

// ─── Step 1 helpers — pure builders for buildParams ──────────────────────────

/**
 * Resolves the effective page and limit for a request.
 * Priority: paramOverrides > initialSearch fields > store.pagination.
 */
export function resolvePagination(
    storePagination: StorePagination,
    currentSearch: ISearchParams,
    overrides?: { page?: number; limit?: number }
): PaginationResult {
    const merged = {
        ...storePagination,
        ...(currentSearch.page !== undefined && { page: currentSearch.page }),
        ...(currentSearch.limit !== undefined && { limit: currentSearch.limit }),
        ...overrides,
    }
    return {
        page: (merged.page as number) ?? 1,
        limit: (merged.limit as number) ?? 20,
    }
}

// ─── mergeFilterParams sub-helpers (complexity budget) ───────────────────────

function resolveFilters(
    currentSearch: ISearchParams,
    overrideFilters?: OverrideParams['filters']
): Array<{ field: string; operator: string; value: unknown }> | undefined {
    if (!overrideFilters?.length && !currentSearch.filters?.length) return undefined
    return [
        ...(currentSearch.filters || []),
        ...(overrideFilters || []),
    ]
}

function resolveSort(
    currentSearch: ISearchParams,
    overrideSort?: OverrideParams['sort']
): Array<{ field: string; direction?: 'asc' | 'desc' }> | undefined {
    if (overrideSort?.length) return overrideSort
    if (currentSearch.sort?.length) return currentSearch.sort
    return undefined
}

function resolveIncludes(
    currentSearch: ISearchParams,
    overrideIncludes?: OverrideParams['includes']
): Array<{ relation: string }> | undefined {
    if (!overrideIncludes?.length && !currentSearch.includes?.length) return undefined
    const allIncludes = [
        ...(currentSearch.includes || []),
        ...(overrideIncludes || []),
    ]
    return allIncludes.filter((include, index, self) =>
        index === self.findIndex(i => i.relation === include.relation)
    )
}

/**
 * Merges user search filters/sort/includes with overrideParams.
 * - filters: user first, then overrides (concatenated)
 * - sort: overrideSort wins; falls back to user sort
 * - includes: concat + dedup by relation (first occurrence wins)
 */
export function mergeFilterParams(
    currentSearch: ISearchParams,
    overrideParams: OverrideParams
): Record<string, unknown> {
    const { filters: overrideFilters, sort: overrideSort, includes: overrideIncludes, ...otherOverrides } = overrideParams
    const partial: Record<string, unknown> = { ...otherOverrides }

    const filters = resolveFilters(currentSearch, overrideFilters)
    if (filters) partial.filters = filters

    const sort = resolveSort(currentSearch, overrideSort)
    if (sort) partial.sort = sort

    const includes = resolveIncludes(currentSearch, overrideIncludes)
    if (includes) partial.includes = includes

    return partial
}

/**
 * Builds the no-override branch: user filters/sort/includes pass through as-is.
 */
export function mergeFilterParamsNoOverride(currentSearch: ISearchParams): Record<string, unknown> {
    const partial: Record<string, unknown> = {}

    if (currentSearch.filters?.length) {
        partial.filters = currentSearch.filters
    }
    if (currentSearch.sort?.length) {
        partial.sort = currentSearch.sort
    }
    if (currentSearch.includes?.length) {
        partial.includes = currentSearch.includes
    }

    return partial
}

/**
 * Builds the extra search fields: search value, aggregates, user_id, scopes, active.
 */
export function buildSearchExtras(currentSearch: ISearchParams): Record<string, unknown> {
    const partial: Record<string, unknown> = {}

    if (currentSearch.search?.value) {
        partial.search = {
            value: currentSearch.search.value.trim(),
            case_sensitive: false,
        }
    }

    if (currentSearch.aggregates?.length) {
        partial.aggregates = currentSearch.aggregates
    }

    if (currentSearch.user_id) {
        partial.user_id = currentSearch.user_id
    }

    if (currentSearch.scopes?.length) {
        partial.scopes = currentSearch.scopes
    }

    if (currentSearch.active !== undefined) {
        partial.active = currentSearch.active
    }

    if (currentSearch.owner_ids?.length) {
        partial.owner_ids = currentSearch.owner_ids
    }

    return partial
}

// ─── Step 2 helpers — pure builders for getList pagination ───────────────────

/**
 * Builds pagination update from a response that CONTAINS a meta object.
 */
export function paginationFromMeta(
    meta: IPaginationMeta,
    params: { page?: number; limit?: number },
    dataLength: number,
    total?: number
): PaginationUpdate {
    const currentPage = meta.current_page || (params.page as number) || 1
    const pageSize = meta.per_page || (params.limit as number) || 20
    const totalItems = meta.total || (total as number) || dataLength

    return {
        page: currentPage,
        limit: pageSize,
        total: totalItems,
        pages: meta.last_page || Math.ceil(totalItems / pageSize),
        per_page: pageSize,
        from: meta.from || (currentPage - 1) * pageSize + 1,
        to: meta.to || Math.min(currentPage * pageSize, totalItems),
    }
}

/**
 * Builds pagination update from a response that has NO meta.
 * Preserves the documented known bug: the `data.length === pageSize → page + 1`
 * heuristic branch is effectively dead code because totalItems always falls
 * back to data.length (non-zero), making Math.ceil(totalItems / pageSize) always
 * produce the correct value. DO NOT FIX — pinned by test case 8.
 */
export function paginationFromHeuristic(
    params: { page?: number; limit?: number },
    dataLength: number,
    total?: number
): PaginationUpdate {
    const currentPage = (params.page as number) || 1
    const pageSize = (params.limit as number) || 20
    const totalItems = (total as number) || dataLength

    let totalPages: number
    if (totalItems && totalItems > 0) {
        totalPages = Math.ceil(totalItems / pageSize)
    } else if (dataLength === pageSize) {
        totalPages = currentPage + 1
    } else {
        totalPages = currentPage
    }

    return {
        page: currentPage,
        limit: pageSize,
        total: totalItems || dataLength,
        pages: totalPages,
        per_page: pageSize,
        from: dataLength > 0 ? (currentPage - 1) * pageSize + 1 : null,
        to: dataLength > 0 ? (currentPage - 1) * pageSize + dataLength : null,
    }
}
