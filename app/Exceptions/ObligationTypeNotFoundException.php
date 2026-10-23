<?php

namespace App\Exceptions;

use RuntimeException;

class ObligationTypeNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Obligation type not found in this household.')
    {
        parent::__construct($message);
    }
}
