<?php

namespace App\Exceptions;

use RuntimeException;

class MaintenanceAlreadyCancelledException extends RuntimeException
{
    public function __construct(string $message = 'Maintenance is already cancelled.')
    {
        parent::__construct($message);
    }
}
