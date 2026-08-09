import type { ReactNode } from 'react'

export type TimelineEventType =
  | 'stage_move'
  | 'note'
  | 'interaction'
  | 'created'
  | 'updated'
  | 'deleted'
  | 'generic'

export interface TimelineEvent {
  id: string
  type: TimelineEventType
  title: string
  description?: string
  actor_name: string
  actor_avatar?: string
  occurred_at: string
  icon?: ReactNode
}
