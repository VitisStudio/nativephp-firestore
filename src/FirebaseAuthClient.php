<?php

namespace VitisStudio\NativeFirestore;

use VitisStudio\NativeFirestore\Contracts\AuthDriver;
use VitisStudio\NativeFirestore\DTO\User;

/**
 * The object behind the FirebaseAuth facade. The signed-in user is shared
 * with Firestore natively, so security rules see request.auth.
 */
class FirebaseAuthClient
{
    public function __construct(private readonly AuthDriver $driver) {}

    public function signInWithEmailAndPassword(string $email, string $password): User
    {
        return User::fromArray($this->driver->signInWithEmailAndPassword($email, $password));
    }

    /**
     * Create an account and sign it in.
     */
    public function createUser(string $email, string $password): User
    {
        return User::fromArray($this->driver->createUserWithEmailAndPassword($email, $password));
    }

    public function createUserWithEmailAndPassword(string $email, string $password): User
    {
        return $this->createUser($email, $password);
    }

    public function signInAnonymously(): User
    {
        return User::fromArray($this->driver->signInAnonymously());
    }

    public function signOut(): void
    {
        $this->driver->signOut();
    }

    public function currentUser(): ?User
    {
        $user = $this->driver->currentUser();

        return $user === null ? null : User::fromArray($user);
    }

    public function check(): bool
    {
        return $this->driver->currentUser() !== null;
    }

    /**
     * The signed-in user's ID token (a JWT for your own backend), or null
     * when nobody is signed in.
     */
    public function getIdToken(bool $forceRefresh = false): ?string
    {
        return $this->driver->getIdToken($forceRefresh);
    }

    public function sendPasswordResetEmail(string $email): void
    {
        $this->driver->sendPasswordResetEmail($email);
    }

    /**
     * Start dispatching AuthStateChanged events (one immediately with the
     * current state, then one per sign-in/sign-out).
     */
    public function listen(): void
    {
        $this->driver->listen();
    }

    public function stopListening(): void
    {
        $this->driver->stopListening();
    }

    public function driver(): AuthDriver
    {
        return $this->driver;
    }
}
