<?php

namespace App\Exceptions;

use RuntimeException;

class ObligationTypeInUseException extends RuntimeException
{
    public function __construct(string $message = 'Obligation type is still used by an obligation and cannot be deleted.')
    {
        parent::__construct($message);
    }
}
