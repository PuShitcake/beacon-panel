<?php

namespace Pterodactyl\Beacon\Content\Exceptions;

/** Provider metadata cannot supply a single valid expected file checksum. */
class UnverifiableFileException extends ProviderResponseException
{
}
