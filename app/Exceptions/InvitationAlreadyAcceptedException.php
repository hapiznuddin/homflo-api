<?php

namespace App\Exceptions;

use RuntimeException;

class InvitationAlreadyAcceptedException extends RuntimeException
{
    public function __construct(string $message = 'Invitation has already been accepted.')
    {
        parent::__construct($message);
    }
}
