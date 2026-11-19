<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * The address is another account's sign-in address and was never confirmed
 * (#2245). It can't be given to a new owner without breaking that account, and
 * can't be linked to it without proof that the account holds the address.
 */
final class UnconfirmedEmailClaimException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This email address is waiting to be confirmed on another account');
    }
}
