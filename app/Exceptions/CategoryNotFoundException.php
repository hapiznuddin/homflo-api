<?php

namespace App\Exceptions;

use RuntimeException;

class CategoryNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Category not found.')
    {
        parent::__construct($message);
    }
}
