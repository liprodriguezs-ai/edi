---
name: new-date-input-defaults
description: new Date().toISOString().slice(0,10) for input default values is NOT a violation; the dateHelper ban only covers display formatting
metadata:
  type: discovery
---

CLAUDE.md says: "new Date() for formatting: Always use displayFormatDate() from @/shared/utils/dateHelper". The key word is "formatting" (display). `new Date().toISOString().slice(0, 10)` used to set the default value of an `<input type="date">` is a string ISO extraction, not display formatting. `dateHelper` exports `displayFormatDate` and `formatDateForInput` — neither provides "today as YYYY-MM-DD". This pattern is correct and should not be flagged.

**Why:** Clarified on 2026-06-12 while reviewing SubmitPaymentSheet, ContributeModal, CollaboratorDetail.
