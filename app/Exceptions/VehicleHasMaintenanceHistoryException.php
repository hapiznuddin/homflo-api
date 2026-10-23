<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleHasMaintenanceHistoryException extends RuntimeException
{
    public function __construct(string $message = 'Vehicle still has maintenance history and cannot be deleted.')
    {
        parent::__construct($message);
    }
}
