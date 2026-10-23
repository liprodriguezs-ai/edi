import { TENANT_STATUS_CONFIG, type TenantStatus } from '@/modules/tenant/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

function isKnownStatus(status: string): status is TenantStatus {
  return Object.hasOwn(TENANT_STATUS_CONFIG, status)
}

/** Tenant status pill; unknown statuses render their raw value in a neutral tone. */
export function TenantStatusPill({ status }: { status: string }) {
  const config = isKnownStatus(status) ? TENANT_STATUS_CONFIG[status] : undefined
  return <StatusPill tone={config?.tone ?? 'n'}>{config?.label ?? status}</StatusPill>
}
