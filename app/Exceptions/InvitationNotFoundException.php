<?php

namespace App\Exceptions;

use RuntimeException;

class InvitationNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Invitation not found.')
    {
        parent::__construct($message);
    }
}
