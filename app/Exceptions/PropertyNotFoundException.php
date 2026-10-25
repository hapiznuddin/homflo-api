<?php

namespace App\Exceptions;

use RuntimeException;

class PropertyNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Property not found.')
    {
        parent::__construct($message);
    }
}
