<?php

namespace App\Exceptions;

use RuntimeException;

class InvitationExpiredException extends RuntimeException
{
    public function __construct(string $message = 'Invitation has expired.')
    {
        parent::__construct($message);
    }
}
