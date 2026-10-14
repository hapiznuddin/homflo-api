<?php

namespace App\Exceptions;

use RuntimeException;

class CategoryAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'Category already exists in this household.')
    {
        parent::__construct($message);
    }
}
