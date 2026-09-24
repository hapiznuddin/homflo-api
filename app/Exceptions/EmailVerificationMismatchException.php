<?php

namespace App\Exceptions;

use RuntimeException;

class EmailVerificationMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Verification link is not valid for this user.')
    {
        parent::__construct($message);
    }
}
