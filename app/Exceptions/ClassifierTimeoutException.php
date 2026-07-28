<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The classifier did not respond within the time budget (network-level timeout).
 * Maps to HTTP 504.
 */
class ClassifierTimeoutException extends RuntimeException {}
