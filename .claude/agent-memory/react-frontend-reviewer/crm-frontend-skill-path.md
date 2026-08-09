---
name: react-frontend-architecture-skill-path
description: Location of the react-frontend-architecture SKILL.md for this project (not co-located with repo)
metadata:
  type: reference
---

The react-frontend-architecture skill is NOT in the monorepo at `.agents/skills/react-frontend-architecture/`. It is a USER-level skill located at:

`/Users/rodriguez/.cursor/skills/react-frontend-architecture/SKILL.md`

The skill-registry.md confirms: scope is "user", path is `/Users/rodriguez/.cursor/skills/react-frontend-architecture/SKILL.md`.

**Why:** The skill is managed at the user level, not the project level, so it's shared across projects that use the same React+Zustand+TanStack stack.

**How to apply:** Always load from that path when reviewing frontend code for this project.
