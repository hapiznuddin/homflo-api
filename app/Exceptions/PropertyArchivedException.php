<?php

namespace App\Exceptions;

use RuntimeException;

class PropertyArchivedException extends RuntimeException
{
    public function __construct(string $message = 'Property is archived and cannot be linked to an asset.')
    {
        parent::__construct($message);
    }
}
