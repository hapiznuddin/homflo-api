<?php

namespace App\Exceptions;

use RuntimeException;

class WalletAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'A wallet with this name already exists in the household.')
    {
        parent::__construct($message);
    }
}
