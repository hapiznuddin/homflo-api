<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPasswordResetTokenException extends RuntimeException
{
    public function __construct(string $message = 'This password reset token is invalid.')
    {
        parent::__construct($message);
    }
}
