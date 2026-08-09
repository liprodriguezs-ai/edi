---
name: catalog-cast-pattern
description: useCatalogs returns IEntity[] and IEntity already has name? — casting (item as {name?}) is redundant but consistent across codebase
metadata:
  type: discovery
---

`CatalogResponse` is typed as `{ [key in CatalogType]?: IEntity[] }`. `IEntity` (src/shared/interfaces/Entity.ts) already declares `name?: string`. The recurring cast `(item as { name?: string }).name` seen in SubmitPaymentSheet, ContributeModal, VisitorFormModal, ChatPage, AccessNowPage is therefore unnecessary — `item.name` already typechecks. This appears to be a cargo-cult pattern from before IEntity had `name?`. All new code using useCatalogs can just write `item.name` directly.

**Why:** Reviewing session work in 2026-06-12 — found this in 5+ new components.
**How to apply:** Flag in reviews; simple fix is removing the cast.
