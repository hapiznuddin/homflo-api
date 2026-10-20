<?php

namespace App\Exceptions;

use RuntimeException;

class BillPaymentImmutableException extends RuntimeException
{
    public function __construct(string $message = 'Bill payments are immutable and cannot be changed or deleted.')
    {
        parent::__construct($message);
    }
}
