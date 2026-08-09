<?php

namespace Modules\Treasury\Services;

/**
 * Business rules for bank accounts.
 *
 * Responsibilities:
 * - Ensure current_balance is initialised to initial_balance on creation.
 *
 * Note: balance mutations are handled by each consuming module
 * (Collections/PaymentService, Treasury/ExpenseService) via their own
 * locked read-modify-write helpers.
 */
class BankAccountService {}
