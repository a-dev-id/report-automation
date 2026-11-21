<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The hotel is missing an identifier needed for the call, such as its GA4 property ID. */
class PropertyNotConfiguredException extends GoogleApiException
{
}
