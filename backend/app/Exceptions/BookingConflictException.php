<?php

namespace App\Exceptions;

use Exception;

class BookingConflictException extends Exception
{
    public array $conflictingBedIds;

    public function __construct(string $message, array $conflictingBedIds = [])
    {
        parent::__construct($message);
        $this->conflictingBedIds = $conflictingBedIds;
    }
}
