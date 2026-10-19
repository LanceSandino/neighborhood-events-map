<?php

declare(strict_types=1);

namespace App\Address;

/**
 * @module Address/InvalidAddressException
 * @description User-correctable address validation failure (message is safe to display).
 */
final class InvalidAddressException extends \InvalidArgumentException
{
}
