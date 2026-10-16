<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidTransferException extends RuntimeException
{
    public function __construct(string $message = 'Invalid transfer.')
    {
        parent::__construct($message);
    }
}
