<?php

declare(strict_types=1);

// Stand-ins of defuse/php-encryption's exception hierarchy (same names and parents), loaded by tests/bootstrap.php only
// when Defuse is not installed.

namespace Defuse\Crypto\Exception;

use Exception;

class CryptoException extends Exception
{
}

class BadFormatException extends CryptoException
{
}

class WrongKeyOrModifiedCiphertextException extends CryptoException
{
}
