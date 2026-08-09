<?php

namespace Modules\Collections\Support;

/**
 * Global money sanity cap (spec §18.7.4): amounts are positive and < $10,000,000 MXN.
 * In minor units (centavos) that is < 1,000,000,000 → validation max 999,999,999.
 */
final class MoneyLimit
{
    public const MAX_MINOR_UNITS = 999999999;
}
