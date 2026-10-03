<?php

namespace VitisStudio\NativeFirestore\Facades;

use Illuminate\Support\Facades\Facade;
use VitisStudio\NativeFirestore\FirebaseAuthClient;
use VitisStudio\NativeFirestore\Testing\FakeFirebaseAuth;

/**
 * @method static \VitisStudio\NativeFirestore\DTO\User signInWithEmailAndPassword(string $email, string $password)
 * @method static \VitisStudio\NativeFirestore\DTO\User createUser(string $email, string $password)
 * @method static \VitisStudio\NativeFirestore\DTO\User createUserWithEmailAndPassword(string $email, string $password)
 * @method static \VitisStudio\NativeFirestore\DTO\User signInAnonymously()
 * @method static void signOut()
 * @method static \VitisStudio\NativeFirestore\DTO\User|null currentUser()
 * @method static bool check()
 * @method static string|null getIdToken(bool $forceRefresh = false)
 * @method static void sendPasswordResetEmail(string $email)
 * @method static void listen()
 * @method static void stopListening()
 *
 * @see FirebaseAuthClient
 */
class FirebaseAuth extends Facade
{
    public static function fake(): FakeFirebaseAuth
    {
        $fake = new FakeFirebaseAuth;

        static::swap(new FirebaseAuthClient($fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'firebase.auth';
    }
}
