import { GroupItemsAccordion } from '@/modules/instrument/components/GroupItemsAccordion'
import { useAgeGroups } from '@/modules/instrument/hooks/useInstrumentQueries'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'

export function GroupsTab({ versionId }: { versionId: string }) {
  const { data: groups, isLoading } = useAgeGroups(versionId)

  if (isLoading) return <InlineLoading name='instrument-groups' />
  if (!groups || groups.length === 0) return <InlineEmpty>Sin grupos de edad.</InlineEmpty>

  return (
    <div className="divide-y divide-border border border-border">
      {groups.map((group) => (
        <GroupItemsAccordion key={group.id} group={group} />
      ))}
    </div>
  )
}
