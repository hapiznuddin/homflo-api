<?php

namespace App\Exceptions;

use RuntimeException;

class BillArchivedException extends RuntimeException
{
    public function __construct(string $message = 'Archived bills cannot receive new payments.')
    {
        parent::__construct($message);
    }
}
