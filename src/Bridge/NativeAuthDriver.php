<?php

namespace VitisStudio\NativeFirestore\Bridge;

use VitisStudio\NativeFirestore\Contracts\AuthDriver;

class NativeAuthDriver implements AuthDriver
{
    public function __construct(private readonly NativeBridge $bridge) {}

    public function signInWithEmailAndPassword(string $email, string $password): array
    {
        return (array) $this->bridge->call('FirebaseAuth.SignInWithEmailAndPassword', compact('email', 'password'))['user'];
    }

    public function createUserWithEmailAndPassword(string $email, string $password): array
    {
        return (array) $this->bridge->call('FirebaseAuth.CreateUserWithEmailAndPassword', compact('email', 'password'))['user'];
    }

    public function signInAnonymously(): array
    {
        return (array) $this->bridge->call('FirebaseAuth.SignInAnonymously')['user'];
    }

    public function signOut(): void
    {
        $this->bridge->call('FirebaseAuth.SignOut');
    }

    public function currentUser(): ?array
    {
        $user = $this->bridge->call('FirebaseAuth.CurrentUser')['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    public function getIdToken(bool $forceRefresh): ?string
    {
        $token = $this->bridge->call('FirebaseAuth.GetIdToken', ['forceRefresh' => $forceRefresh])['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function sendPasswordResetEmail(string $email): void
    {
        $this->bridge->call('FirebaseAuth.SendPasswordResetEmail', compact('email'));
    }

    public function listen(): void
    {
        $this->bridge->call('FirebaseAuth.Listen');
    }

    public function stopListening(): void
    {
        $this->bridge->call('FirebaseAuth.StopListening');
    }
}
