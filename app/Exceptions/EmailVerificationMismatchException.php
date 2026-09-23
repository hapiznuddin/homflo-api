<?php

namespace App\Exceptions;

use RuntimeException;

class EmailVerificationMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Verification link does not belong to the authenticated user.')
    {
        parent::__construct($message);
    }
}
