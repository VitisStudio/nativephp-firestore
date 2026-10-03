<?php

namespace VitisStudio\NativeFirestore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for every error the plugin raises, carrying the Firebase error code
 * (e.g. "permission-denied") as a string because PHP's own exception code is
 * an integer.
 */
class FirebaseException extends RuntimeException
{
    /** The plugin is not running inside a NativePHP build that includes it. */
    public const BRIDGE_UNAVAILABLE = 'bridge-unavailable';

    /** No FirebaseApp could be configured (no config file, no options). */
    public const NOT_CONFIGURED = 'not-configured';

    public const UNKNOWN = 'unknown';

    public function __construct(
        string $message,
        protected string $errorCode = self::UNKNOWN,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function is(string $errorCode): bool
    {
        return $this->errorCode === $errorCode;
    }

    public function isNotConfigured(): bool
    {
        return $this->is(self::NOT_CONFIGURED);
    }

    public function isBridgeUnavailable(): bool
    {
        return $this->is(self::BRIDGE_UNAVAILABLE);
    }
}
