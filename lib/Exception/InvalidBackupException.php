<?php

declare(strict_types=1);

namespace OCA\TravelManager\Exception;

/**
 * An uploaded file is not a backup this version can restore. The message names
 * the offending field and is shown to the user as it stands, so nothing here may
 * quote more of the file than the field's position.
 */
class InvalidBackupException extends \RuntimeException {
}
