---
name: as-never-cast
description: Using as never to pass include params to resource.show() is a recurring type hack; createCrudApi show() takes Partial<TForm> not a generic params object
metadata:
  type: discovery
---

`createCrudApi` show signature: `show(id, params?: Partial<TForm>)`. When a component needs to pass `include` params (not part of TForm), it uses `as never` to bypass the type system. Seen in `CommunityProjectDetailPage.tsx:49`. The proper fix is to either (a) add a dedicated `showWithIncludes` custom method to the resource, or (b) extend the `createCrudApi` show signature to accept an optional generic params object separate from TForm.

**Why:** Found in CommunityProjectDetailPage.tsx on 2026-06-12 review.
**How to apply:** Flag `as never` casts in show() calls as an important fix.
