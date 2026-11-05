<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** Falla al hablar con Mercado Pago o PayPal. El mensaje es para el log, no para el usuario. */
class PaymentException extends RuntimeException
{
}
