<?php

declare(strict_types=1);

// Stand-ins of paragonie/halite's alert hierarchy (same names and parents), loaded by tests/bootstrap.php only when
// Halite is not installed: the DoctrineEncrypt box tells key errors from value errors by these classes.

namespace ParagonIE\Halite\Alerts;

use Exception;

class HaliteAlert extends Exception
{
}

class InvalidKey extends HaliteAlert
{
}

class CannotPerformOperation extends HaliteAlert
{
}

class InvalidMessage extends HaliteAlert
{
}

class InvalidSignature extends HaliteAlert
{
}
