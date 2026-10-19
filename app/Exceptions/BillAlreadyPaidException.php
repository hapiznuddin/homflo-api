<?php

namespace App\Exceptions;

use RuntimeException;

class BillAlreadyPaidException extends RuntimeException
{
    public function __construct(string $message = 'Bill is already paid and can no longer be changed.')
    {
        parent::__construct($message);
    }
}
