# Skills (estándar para agentes)

Cada skill vive en su propia carpeta bajo `.cursor/skills/`:

```
.cursor/skills/
├── README.md           # Este archivo
├── my-skills/
│   └── SKILL.md        # Obligatorio: frontmatter YAML (name, description) + cuerpo
└── <otro-skill>/
    └── SKILL.md
```

**Reglas:**
- Una carpeta por skill; nombre en minúsculas con guiones (ej. `my-skills`).
- Dentro, `SKILL.md` con bloque YAML al inicio:
  - `name`: identificador del skill (≤64 chars, lowercase, guiones).
  - `description`: qué hace y cuándo usarlo (para que el agente lo detecte).
- Sin subcarpetas tipo `core/skills/`; la ruta canónica es `.cursor/skills/<nombre-skill>/SKILL.md`.

Cursor y otros agentes que sigan esta convención encontrarán los skills en esta ruta.
