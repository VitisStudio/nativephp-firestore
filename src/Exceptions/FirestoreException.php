<?php

namespace VitisStudio\NativeFirestore\Exceptions;

/**
 * A Firestore operation failed. Codes mirror the Firestore SDKs' canonical
 * gRPC codes in their kebab-case spelling.
 */
class FirestoreException extends FirebaseException
{
    public const CANCELLED = 'cancelled';

    public const INVALID_ARGUMENT = 'invalid-argument';

    public const DEADLINE_EXCEEDED = 'deadline-exceeded';

    public const NOT_FOUND = 'not-found';

    public const ALREADY_EXISTS = 'already-exists';

    public const PERMISSION_DENIED = 'permission-denied';

    public const RESOURCE_EXHAUSTED = 'resource-exhausted';

    public const FAILED_PRECONDITION = 'failed-precondition';

    public const ABORTED = 'aborted';

    public const OUT_OF_RANGE = 'out-of-range';

    public const UNIMPLEMENTED = 'unimplemented';

    public const INTERNAL = 'internal';

    public const UNAVAILABLE = 'unavailable';

    public const DATA_LOSS = 'data-loss';

    public const UNAUTHENTICATED = 'unauthenticated';

    public function isPermissionDenied(): bool
    {
        return $this->is(self::PERMISSION_DENIED);
    }

    public function isNotFound(): bool
    {
        return $this->is(self::NOT_FOUND);
    }

    public function isUnavailable(): bool
    {
        return $this->is(self::UNAVAILABLE);
    }

    public function isAborted(): bool
    {
        return $this->is(self::ABORTED);
    }

    public function isDeadlineExceeded(): bool
    {
        return $this->is(self::DEADLINE_EXCEEDED);
    }

    public function isUnauthenticated(): bool
    {
        return $this->is(self::UNAUTHENTICATED);
    }
}
