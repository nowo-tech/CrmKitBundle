<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Import;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Exception\CrmException;

/**
 * A file the import cannot read; the message is a translation key (`import.error.*`).
 */
final class ImportFileException extends InvalidArgumentException implements CrmException
{
}
