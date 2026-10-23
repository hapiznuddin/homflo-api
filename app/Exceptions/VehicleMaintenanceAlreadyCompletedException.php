<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleMaintenanceAlreadyCompletedException extends RuntimeException
{
    public function __construct(string $message = 'Vehicle maintenance is already completed.')
    {
        parent::__construct($message);
    }
}
