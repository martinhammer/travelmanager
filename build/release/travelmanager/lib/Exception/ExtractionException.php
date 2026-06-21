<?php

declare(strict_types=1);

namespace OCA\TravelManager\Exception;

/**
 * Thrown when an LLM response cannot be parsed/validated into bookings,
 * after the repair step has been attempted.
 */
class ExtractionException extends \RuntimeException {
}
