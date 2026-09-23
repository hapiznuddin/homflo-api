<?php

namespace App\Exceptions;

use RuntimeException;

class InvitationAlreadyPendingException extends RuntimeException
{
    public function __construct(string $message = 'An invitation for this email is already pending.')
    {
        parent::__construct($message);
    }
}
