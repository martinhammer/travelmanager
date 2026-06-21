<?php

declare(strict_types=1);

namespace OCA\TravelManager\Exception;

/**
 * Thrown by the IMAP layer on connection, authentication or protocol failure.
 */
class ImapException extends \RuntimeException {
}
