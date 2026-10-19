<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Internal integrity failure for the materialization backstop (D-06).
 *
 * The row lock plus existence recheck must make a duplicate period
 * impossible; if the unique partial index ever fires anyway, the whole
 * transaction rolls back and the request fails loudly (HTTP 500 via the
 * global handler). This is deliberately not an API error code: no
 * client action can legitimately trigger it.
 */
class BillPeriodAlreadyExistsException extends RuntimeException
{
    public function __construct(
        string $message = 'Recurrence period was already materialized by a concurrent writer.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
