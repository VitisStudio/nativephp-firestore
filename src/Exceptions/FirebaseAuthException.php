<?php

namespace VitisStudio\NativeFirestore\Exceptions;

/**
 * A Firebase Authentication call failed. Codes follow the SDKs' error names
 * in kebab-case (ERROR_INVALID_EMAIL becomes "invalid-email").
 */
class FirebaseAuthException extends FirebaseException
{
    public const INVALID_EMAIL = 'invalid-email';

    public const INVALID_CREDENTIAL = 'invalid-credential';

    public const WRONG_PASSWORD = 'wrong-password';

    public const USER_NOT_FOUND = 'user-not-found';

    public const USER_DISABLED = 'user-disabled';

    public const EMAIL_ALREADY_IN_USE = 'email-already-in-use';

    public const WEAK_PASSWORD = 'weak-password';

    public const TOO_MANY_REQUESTS = 'too-many-requests';

    public const NETWORK_REQUEST_FAILED = 'network-request-failed';

    public const REQUIRES_RECENT_LOGIN = 'requires-recent-login';

    public const OPERATION_NOT_ALLOWED = 'operation-not-allowed';

    public const NO_CURRENT_USER = 'no-current-user';
}
