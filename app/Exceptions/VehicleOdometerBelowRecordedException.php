<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleOdometerBelowRecordedException extends RuntimeException
{
    public function __construct(string $message = 'Odometer cannot be lower than a recorded service odometer.')
    {
        parent::__construct($message);
    }
}
