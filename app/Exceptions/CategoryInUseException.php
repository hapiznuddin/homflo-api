<?php

namespace App\Exceptions;

use RuntimeException;

class CategoryInUseException extends RuntimeException
{
    public function __construct(string $message = 'Category is still used by a budget.')
    {
        parent::__construct($message);
    }
}
