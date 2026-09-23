<?php

namespace App\Exceptions;

use RuntimeException;

class AlreadyHouseholdMemberException extends RuntimeException
{
    public function __construct(string $message = 'User is already a member of this household.')
    {
        parent::__construct($message);
    }
}
