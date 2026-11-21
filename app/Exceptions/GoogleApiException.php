<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Any failure talking to GA4 or Search Console. The message is safe to store on the report. */
class GoogleApiException extends RuntimeException
{
}
