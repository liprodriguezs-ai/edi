---
name: skeleton-import
description: Project uses shadcn Skeleton from @/components/ui/skeleton for structural header/profile skeletons; boneyard-js rule applies to list/table loading states
metadata:
  type: discovery
---

CLAUDE.md bans `animate-pulse` and mandates boneyard-js for skeleton loading. However, `CollaboratorProfileHeader.tsx` uses `import { Skeleton } from '@/components/ui/skeleton'` (shadcn) inside a `CollaboratorProfileHeaderSkeleton` function for a structural profile header skeleton — NOT an `animate-pulse` div. This is a real violation per the documented rule.

The rule from CLAUDE.md: "Skeleton loading: Use boneyard-js. `<Skeleton name="name" loading={isLoading}>`. Import from `'boneyard-js/react'`. DO NOT use Tailwind's `animate-pulse`"

The shadcn Skeleton component uses `animate-pulse` internally. Should be replaced with boneyard-js or a conditional render pattern.

**Why:** Found in CollaboratorProfileHeader.tsx on 2026-06-12 review.
**How to apply:** Flag Skeleton from shadcn as a violation in reviews.
