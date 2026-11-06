<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * The gateway could not be asked, or refused for a reason that is not a
 * decline.
 *
 * A decline is an ordinary outcome and comes back as a PaymentResult. This is
 * for the cases where there is no outcome: no key configured, the card could
 * not be attached, the API was unreachable.
 */
class PaymentGatewayException extends RuntimeException {}
