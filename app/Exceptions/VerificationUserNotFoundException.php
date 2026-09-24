<?php

namespace App\Exceptions;

use RuntimeException;

class VerificationUserNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Verification user not found.')
    {
        parent::__construct($message);
    }
}
