<?php

namespace App\Exceptions;

use RuntimeException;

class VehiclePlateAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'Vehicle plate number already exists in this household.')
    {
        parent::__construct($message);
    }
}
