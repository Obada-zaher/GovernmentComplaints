<?php

namespace App\Services\Notifications\Channels;

use RuntimeException;

class ExpoPushDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $deviceNotRegistered = false)
    {
        parent::__construct($message);
    }
}
