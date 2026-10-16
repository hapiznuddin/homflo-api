<?php

namespace App\Exceptions;

use RuntimeException;

class CategoryTypeMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Category type does not match the transaction type.')
    {
        parent::__construct($message);
    }
}
