<?php

declare(strict_types=1);

namespace App\Exceptions;

/** What the payment provider answers with. */
final class PaymentDeclined extends \RuntimeException {}
