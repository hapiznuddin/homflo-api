<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(string $message = 'Operation would make a wallet balance negative.')
    {
        parent::__construct($message);
    }
}
