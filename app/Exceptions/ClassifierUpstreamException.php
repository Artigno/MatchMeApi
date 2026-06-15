<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The classifier provider returned a non-2xx status or an unparseable response.
 * Maps to HTTP 502.
 */
class ClassifierUpstreamException extends RuntimeException {}
