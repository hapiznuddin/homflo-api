<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A transaction sourced from a ListItem is immutable through the generic
 * Money endpoints. Editing or deleting it would desynchronise the item's
 * price snapshot from the ledger, so both operations fail with
 * 422 LIST_EXPENSE_LOCKED (contract §8.5).
 */
class ListExpenseLockedException extends RuntimeException
{
    public function __construct(string $message = 'List item expenses are immutable and cannot be changed or deleted.')
    {
        parent::__construct($message);
    }
}
