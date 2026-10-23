# Source extracts (raw, committed verbatim for provenance)

Data extracted from the legacy production dump (`extendevs_prod_edi.sql`),
which does NOT live in this repo (it contains personal data of minors). These
6 files are the clean, PII-free extracts of the EDI instrument catalog and
the derived scoring matrix; they are the input to the one-shot transform that
produced `../edi-legacy-2013/` and `../edi-2022-2ed/` (design.md §6).

| File | Content |
|---|---|
| `cat_edi_clasificacion.csv` | 29 age groups: 15 official + 14 alternate |
| `cat_edi_evaluacion.csv` | 451 items with their two options and each option's color |
| `cat_edi_frb.csv` | 7 biological risk factors (FRB) |
| `cat_edi_posicion.csv` | 6 application positions |
| `cat_edi_perimetro_cefalico.csv` | 118 head-circumference percentile rows (months 1-59, both sexes) |
| `area_item_counts.csv` | group x area x item-count matrix, derived from `cat_edi_evaluacion.csv` |

## Extraction date and known anomalies

Extracted 2026-08 for the `edi-instrument-catalog` change. Known anomalies
in the source, all handled explicitly by the transform (design.md §6/D8/D9):

- **Sentinel age ranges**: alternate groups (`GRUPO 1A`...`GRUPO 14A`) carry
  garbage placeholder day/month ranges in `cat_edi_clasificacion.csv`
  (`9992`...`9915`), including a data-entry typo in row 26 (`GRUPO 11A`):
  `edad_dias_inicio = 99912` instead of `9912`. All of these are DISCARDED at
  transform time — alternates store `NULL` age ranges and are reached only
  via `age_group.alternate_age_group_id` (D8).
- **`CO-R` item codes**: the 3 items of `GRUPO 12A` in area `CO` use the
  literal source code `CO-R 1` / `CO-R 2` / `CO-R 3` instead of the
  `CO12.n` pattern every other CO/axis item follows. The transform keeps the
  code verbatim but normalizes `development_area_id` to the same `CO` catalog
  row (D9) — confirmed the only occurrence in the 451-item bank.
- **Green-first items**: 4 items (`ALE15.1`-`ALE15.4`) present their GREEN
  option first (`valor1`/`color1` = green) instead of the usual red/yellow
  first. Option order is preserved verbatim from the source
  (`screening_item_option.sort_order`), never assumed (D11).
- **Multi-line question text**: several items (e.g. `ALE1.1`, `ALE1.2`, and
  the group-12 `material` note) embed literal newlines and `<br>` HTML markup
  inside a quoted CSV field. Preserved verbatim in `question`/`materials_note`
  — the frontend inspector converts `<br>` to line breaks only at render time
  (design.md §11 Q6).

## What is deliberately NOT here

The raw transactional tables (`tran_edi_beneficiario`,
`tran_edi_evaluacion_respuestas`, `tran_edi_frb_respuestas`) were extracted
to build an oracle and discarded immediately: they contain free-text clinical
notes written by staff about real children, with no cheap way to guarantee
1,834+ of them are free of identifiable data. The oracle itself
(`edi_oracle.jsonl`, 1,836 replayed screenings, PII-free — no name, CURP, or
person identifier) lives in `openspec/changes/edi-instrument-catalog/_source/`
and informed design.md §13, but is NOT copied into this module: it is planning
evidence for the rule transcription, not instrument content the app seeds or
serves.
