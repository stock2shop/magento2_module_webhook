<?php

namespace Stock2Shop\OrderExport\Observer;

/**
 * Order Line Error
 */
class OrderLineError extends \Exception
{
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
