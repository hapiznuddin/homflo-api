<?php

namespace App\Exceptions;

use RuntimeException;

class BillNotPaidException extends RuntimeException
{
    public function __construct(string $message = 'Bill must be paid before it can be archived.')
    {
        parent::__construct($message);
    }
}
