<?php

namespace VitisStudio\NativeFirestore\Testing;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use VitisStudio\NativeFirestore\Contracts\AuthDriver;
use VitisStudio\NativeFirestore\Events\AuthStateChanged;
use VitisStudio\NativeFirestore\Exceptions\FirebaseAuthException;

/**
 * In-memory Firebase Authentication with the SDKs' error codes (including
 * email-enumeration protection: an unknown email and a wrong password both
 * fail with invalid-credential).
 */
class FakeFirebaseAuth implements AuthDriver
{
    /** @var array<string, array<string, mixed>> uid => user (+ password) */
    private array $users = [];

    private ?string $currentUid = null;

    private bool $listening = false;

    /** @var list<string> */
    private array $passwordResets = [];

    /** @var list<array{0: class-string, 1: array<string, mixed>}> */
    private array $emitted = [];

    // -------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------

    /**
     * Register an account without signing it in.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addUser(string $email, string $password, array $attributes = []): array
    {
        $uid = $attributes['uid'] ?? Str::random(28);

        $this->users[$uid] = array_merge([
            'uid' => $uid,
            'email' => strtolower($email),
            'displayName' => null,
            'photoUrl' => null,
            'phoneNumber' => null,
            'emailVerified' => false,
            'isAnonymous' => false,
            'providerId' => 'firebase',
            'createdAt' => now()->getTimestampMs(),
            'lastSignInAt' => null,
        ], $attributes, ['uid' => $uid, 'password' => $password]);

        return $this->publicUser($uid);
    }

    /**
     * Sign in as an existing (or freshly created) account without a password check.
     */
    public function actingAs(string $email): static
    {
        $uid = $this->uidFor($email) ?? $this->addUser($email, Str::random(12))['uid'];
        $this->switchUser($uid);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function passwordResetsSent(): array
    {
        return $this->passwordResets;
    }

    /**
     * @return list<array{0: class-string, 1: array<string, mixed>}>
     */
    public function emittedEvents(): array
    {
        return $this->emitted;
    }

    public function assertSignedIn(?string $email = null): static
    {
        PHPUnit::assertNotNull($this->currentUid, 'Expected a signed-in Firebase user.');

        if ($email !== null) {
            PHPUnit::assertSame(strtolower($email), $this->users[$this->currentUid]['email']);
        }

        return $this;
    }

    public function assertSignedOut(): static
    {
        PHPUnit::assertNull($this->currentUid, 'Expected no signed-in Firebase user.');

        return $this;
    }

    public function assertPasswordResetSent(string $email): static
    {
        PHPUnit::assertContains(strtolower($email), $this->passwordResets, "No password reset email was sent to [{$email}].");

        return $this;
    }

    // -------------------------------------------------------------------
    // AuthDriver
    // -------------------------------------------------------------------

    public function signInWithEmailAndPassword(string $email, string $password): array
    {
        $this->validateEmail($email);
        $uid = $this->uidFor($email);

        if ($uid === null || ! hash_equals((string) $this->users[$uid]['password'], $password)) {
            throw new FirebaseAuthException('The supplied auth credential is incorrect, malformed or has expired.', FirebaseAuthException::INVALID_CREDENTIAL);
        }

        $this->switchUser($uid);

        return $this->publicUser($uid);
    }

    public function createUserWithEmailAndPassword(string $email, string $password): array
    {
        $this->validateEmail($email);

        if ($this->uidFor($email) !== null) {
            throw new FirebaseAuthException('The email address is already in use by another account.', FirebaseAuthException::EMAIL_ALREADY_IN_USE);
        }

        if (strlen($password) < 6) {
            throw new FirebaseAuthException('The password must be 6 characters long or more.', FirebaseAuthException::WEAK_PASSWORD);
        }

        $uid = $this->addUser($email, $password)['uid'];
        $this->switchUser($uid);

        return $this->publicUser($uid);
    }

    public function signInAnonymously(): array
    {
        $uid = Str::random(28);
        $this->users[$uid] = [
            'uid' => $uid,
            'email' => null,
            'emailVerified' => false,
            'isAnonymous' => true,
            'providerId' => 'firebase',
            'createdAt' => now()->getTimestampMs(),
            'password' => null,
        ];

        $this->switchUser($uid);

        return $this->publicUser($uid);
    }

    public function signOut(): void
    {
        $this->switchUser(null);
    }

    public function currentUser(): ?array
    {
        return $this->currentUid === null ? null : $this->publicUser($this->currentUid);
    }

    public function getIdToken(bool $forceRefresh): ?string
    {
        if ($this->currentUid === null) {
            return null;
        }

        $user = $this->users[$this->currentUid];
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode([
            'iss' => 'https://securetoken.google.com/fake-project',
            'aud' => 'fake-project',
            'sub' => $user['uid'],
            'user_id' => $user['uid'],
            'email' => $user['email'],
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
        ]).'.';
    }

    public function sendPasswordResetEmail(string $email): void
    {
        $this->validateEmail($email);

        // Like the SDKs with enumeration protection: unknown emails succeed silently.
        if ($this->uidFor($email) !== null) {
            $this->passwordResets[] = strtolower($email);
        }
    }

    public function listen(): void
    {
        $this->listening = true;
        $this->emitState();
    }

    public function stopListening(): void
    {
        $this->listening = false;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    private function switchUser(?string $uid): void
    {
        $changed = $this->currentUid !== $uid;
        $this->currentUid = $uid;

        if ($uid !== null) {
            $this->users[$uid]['lastSignInAt'] = now()->getTimestampMs();
        }

        if ($changed && $this->listening) {
            $this->emitState();
        }
    }

    private function emitState(): void
    {
        $payload = ['user' => $this->currentUser()];
        $this->emitted[] = [AuthStateChanged::class, $payload];

        event(new AuthStateChanged(...$payload));
    }

    private function uidFor(string $email): ?string
    {
        foreach ($this->users as $uid => $user) {
            if (($user['email'] ?? null) === strtolower($email)) {
                return $uid;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUser(string $uid): array
    {
        $user = $this->users[$uid];
        unset($user['password']);

        return $user;
    }

    private function validateEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new FirebaseAuthException('The email address is badly formatted.', FirebaseAuthException::INVALID_EMAIL);
        }
    }
}
