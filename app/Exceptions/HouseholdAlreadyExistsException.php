<?php

namespace App\Exceptions;

use RuntimeException;

class HouseholdAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'User already belongs to a household.')
    {
        parent::__construct($message);
    }
}
