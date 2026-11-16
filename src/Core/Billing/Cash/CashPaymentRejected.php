<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Cash;

/**
 * A cash payment that can't be recorded or voided as asked (WHIT-1153). Its
 * message is written for the operator and is safe to show: it names what to
 * fix ("Write the receipt number"), never anything internal. Handlers answer
 * with {@see $clientMessage}, never with a raw exception message (WC-186).
 */
final class CashPaymentRejected extends \InvalidArgumentException
{
    public function __construct(public readonly string $clientMessage)
    {
        parent::__construct($clientMessage);
    }
}
