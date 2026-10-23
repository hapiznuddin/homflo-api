<?php

namespace App\Exceptions;

use RuntimeException;

class ObligationTypeAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'Obligation type already exists in this household.')
    {
        parent::__construct($message);
    }
}
