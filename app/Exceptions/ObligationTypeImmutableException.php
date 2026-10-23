<?php

namespace App\Exceptions;

use RuntimeException;

class ObligationTypeImmutableException extends RuntimeException
{
    public function __construct(string $message = 'Built-in obligation types cannot be changed or deleted.')
    {
        parent::__construct($message);
    }
}
