<?php

namespace App\Exceptions;

use RuntimeException;

class InvitedUserNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'No user exists for the invited email.')
    {
        parent::__construct($message);
    }
}
