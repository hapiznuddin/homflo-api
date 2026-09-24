<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidCurrentPasswordException extends RuntimeException
{
    public function __construct(string $message = 'The current password is incorrect.')
    {
        parent::__construct($message);
    }
}
