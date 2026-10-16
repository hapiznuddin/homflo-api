<?php

namespace App\Exceptions;

use RuntimeException;

class WalletInUseException extends RuntimeException
{
    public function __construct(string $message = 'Wallet has financial history and cannot be deleted. Deactivate it instead.')
    {
        parent::__construct($message);
    }
}
