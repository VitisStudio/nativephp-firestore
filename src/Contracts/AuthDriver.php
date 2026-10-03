<?php

namespace VitisStudio\NativeFirestore\Contracts;

/**
 * The operations behind the FirebaseAuth API. Users cross this boundary as
 * the arrays User::fromArray() accepts.
 */
interface AuthDriver
{
    /**
     * @return array<string, mixed>
     */
    public function signInWithEmailAndPassword(string $email, string $password): array;

    /**
     * @return array<string, mixed>
     */
    public function createUserWithEmailAndPassword(string $email, string $password): array;

    /**
     * @return array<string, mixed>
     */
    public function signInAnonymously(): array;

    public function signOut(): void;

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array;

    public function getIdToken(bool $forceRefresh): ?string;

    public function sendPasswordResetEmail(string $email): void;

    public function listen(): void;

    public function stopListening(): void;
}
