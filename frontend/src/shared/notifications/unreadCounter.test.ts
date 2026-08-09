import {
  decrementUnread,
  incrementUnread,
  resetUnread,
  unreadCountFromSearch,
} from '@/shared/notifications/unreadCounter'
import { describe, expect, it } from 'vitest'

describe('unread counter maintenance', () => {
  it('increments on a new push (+1), treating an empty cache as 0', () => {
    expect(incrementUnread(3)).toBe(4)
    expect(incrementUnread(0)).toBe(1)
    expect(incrementUnread(undefined)).toBe(1)
  })

  it('decrements when a notification is read, never below 0', () => {
    expect(decrementUnread(3)).toBe(2)
    expect(decrementUnread(1)).toBe(0)
    expect(decrementUnread(0)).toBe(0)
    expect(decrementUnread(undefined)).toBe(0)
  })

  it('resets to 0 on mark-all-read', () => {
    expect(resetUnread()).toBe(0)
  })
})

describe('unreadCountFromSearch', () => {
  it('reads meta.total from an Orion paginated search response', () => {
    expect(unreadCountFromSearch({ meta: { total: 7 } })).toBe(7)
    expect(unreadCountFromSearch({ meta: { total: 0 } })).toBe(0)
  })

  it('defaults to 0 when meta/total is missing or the payload is empty', () => {
    expect(unreadCountFromSearch({})).toBe(0)
    expect(unreadCountFromSearch({ meta: {} })).toBe(0)
    expect(unreadCountFromSearch(undefined)).toBe(0)
    expect(unreadCountFromSearch(null)).toBe(0)
  })
})
