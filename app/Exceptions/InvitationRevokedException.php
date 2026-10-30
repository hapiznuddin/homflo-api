<?php

namespace App\Exceptions;

use Exception;

class InvitationRevokedException extends Exception
{
    public function __construct()
    {
        parent::__construct('This invitation has been revoked.');
    }
}
