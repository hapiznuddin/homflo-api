<?php

namespace App\Exceptions;

use RuntimeException;

class AssetNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Asset not found.')
    {
        parent::__construct($message);
    }
}
