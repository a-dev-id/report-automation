<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The service account has no access to the GA4 property or Search Console site. Retrying will not help. */
class GooglePermissionDeniedException extends GoogleApiException
{
}
