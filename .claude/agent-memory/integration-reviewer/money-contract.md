---
name: money-contract
description: Backend serializes money via MoneyIntegerCast as IMoney {amount, currency, formatted}; filter values must be raw integers in minor units (cents)
metadata:
  type: project
---

Backend: all money columns cast with `Cknow\Money\Casts\MoneyIntegerCast::class.':MXN'`. When serialized in JSON responses, the cast outputs `{ amount: "150000", currency: "MXN", formatted: "$1,500.00" }`.

For FILTERS sent to `POST /search`, Orion compares against the raw DB bigint. The frontend must send plain integers (minor units), NOT the IMoney object. `pesosToMinorUnits(pesos)` does `Math.round(pesos * 100)` — correct for this purpose.

The test suite confirms: filters send `10000` (integer minor units) and the DB column is bigint.

**Why:** Established during charges-filters review; confirmed by money.ts and ChargeFilterTest.php.
**How to apply:** Any filter on a money column must convert user-entered pesos → minor units before including in the filters array.
