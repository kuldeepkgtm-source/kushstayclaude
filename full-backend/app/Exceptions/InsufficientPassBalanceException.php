<?php

namespace App\Exceptions;

use Exception;

class InsufficientPassBalanceException extends Exception
{
    public function __construct(public int $remaining, public int $requested)
    {
        parent::__construct("Only {$remaining} pass day(s) remaining, {$requested} requested.");
    }
}
