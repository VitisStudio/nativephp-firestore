<?php

namespace VitisStudio\NativeFirestore\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A Firebase Authentication user as reported by the native SDK.
 *
 * @implements Arrayable<string, mixed>
 */
final class User implements Arrayable
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
        public readonly ?string $photoUrl = null,
        public readonly ?string $phoneNumber = null,
        public readonly bool $emailVerified = false,
        public readonly bool $isAnonymous = false,
        public readonly ?string $providerId = null,
        public readonly ?int $createdAt = null,
        public readonly ?int $lastSignInAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $user
     */
    public static function fromArray(array $user): self
    {
        $int = fn (string $key): ?int => isset($user[$key]) ? (int) $user[$key] : null;
        $string = fn (string $key): ?string => isset($user[$key]) && $user[$key] !== '' ? (string) $user[$key] : null;

        return new self(
            uid: (string) $user['uid'],
            email: $string('email'),
            displayName: $string('displayName'),
            photoUrl: $string('photoUrl'),
            phoneNumber: $string('phoneNumber'),
            emailVerified: (bool) ($user['emailVerified'] ?? false),
            isAnonymous: (bool) ($user['isAnonymous'] ?? false),
            providerId: $string('providerId'),
            createdAt: $int('createdAt'),
            lastSignInAt: $int('lastSignInAt'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
