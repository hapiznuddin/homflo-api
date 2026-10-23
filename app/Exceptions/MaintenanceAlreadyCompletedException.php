<?php

namespace App\Exceptions;

use RuntimeException;

class MaintenanceAlreadyCompletedException extends RuntimeException
{
    public function __construct(string $message = 'Maintenance is already completed.')
    {
        parent::__construct($message);
    }
}
