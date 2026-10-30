<?php

namespace App\Exceptions;

use Exception;

class HouseholdNotFoundException extends Exception
{
    public function __construct()
    {
        parent::__construct('Household not found.');
    }
}
