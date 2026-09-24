<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleAuthenticationException extends RuntimeException
{
    public function __construct(string $message = 'Google authentication could not be completed.')
    {
        parent::__construct($message);
    }
}
