<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use VitisStudio\NativeFirestore\DTO\User;

/**
 * Fired after FirebaseAuth::listen(): once with the current state, then on
 * every sign-in and sign-out. $user is null when signed out.
 */
class AuthStateChanged implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>|null  $user
     */
    public function __construct(public ?array $user = null) {}

    public function user(): ?User
    {
        return $this->user === null ? null : User::fromArray($this->user);
    }

    public function signedIn(): bool
    {
        return $this->user !== null;
    }
}
