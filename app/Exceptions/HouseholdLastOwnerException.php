<?php

namespace App\Exceptions;

use RuntimeException;

class HouseholdLastOwnerException extends RuntimeException
{
    public function __construct(string $message = 'Household must have at least one owner.')
    {
        parent::__construct($message);
    }
}
