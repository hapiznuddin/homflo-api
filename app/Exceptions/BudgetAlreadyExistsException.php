<?php

namespace App\Exceptions;

use RuntimeException;

class BudgetAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'Budget already exists for this category and period.')
    {
        parent::__construct($message);
    }
}
