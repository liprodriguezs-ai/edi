/**
 * Stable machine `code` values the backend emits on business-rule rejections
 * (403/422). One shared home so every consumer compares against the SAME
 * string the backend actually sends — a literal typo here (or in a consumer)
 * silently breaks the UI branch it gates (see `unit-limit-enforcement`'s
 * `'unit_limit_reached'` vs `UNIT_LIMIT_EXCEEDED` mismatch, fixed by this
 * same change).
 */
export const BUSINESS_CODES = {
  SUBSCRIPTION_INACTIVE: 'SUBSCRIPTION_INACTIVE',
  DELINQUENT_RESTRICTED: 'DELINQUENT_RESTRICTED',
  UNIT_LIMIT_EXCEEDED: 'UNIT_LIMIT_EXCEEDED',
  QUOTA_EXCEEDED: 'QUOTA_EXCEEDED',
} as const
