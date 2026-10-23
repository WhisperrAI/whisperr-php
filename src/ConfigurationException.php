<?php

declare(strict_types=1);

namespace Whisperr;

/** A key or argument the Whisperr API can never accept. Nothing was sent. */
class ConfigurationException extends \InvalidArgumentException
{
}
