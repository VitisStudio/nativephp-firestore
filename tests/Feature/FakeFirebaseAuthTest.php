<?php

use Illuminate\Support\Facades\Event;
use VitisStudio\NativeFirestore\Events\AuthStateChanged;
use VitisStudio\NativeFirestore\Exceptions\FirebaseAuthException;
use VitisStudio\NativeFirestore\Facades\FirebaseAuth;

beforeEach(function () {
    $this->auth = FirebaseAuth::fake();
});

it('creates a user, signs out and signs back in', function () {
    $user = FirebaseAuth::createUser('ada@example.com', 'secret123');

    expect($user->email)->toBe('ada@example.com')
        ->and(FirebaseAuth::currentUser()->uid)->toBe($user->uid)
        ->and(FirebaseAuth::check())->toBeTrue();

    FirebaseAuth::signOut();
    $this->auth->assertSignedOut();
    expect(FirebaseAuth::currentUser())->toBeNull()
        ->and(FirebaseAuth::getIdToken())->toBeNull();

    $again = FirebaseAuth::signInWithEmailAndPassword('ADA@example.com', 'secret123');
    expect($again->uid)->toBe($user->uid)
        ->and($again->lastSignInAt)->not->toBeNull();
    $this->auth->assertSignedIn('ada@example.com');
});

it('uses the SDK error codes', function (callable $call, string $code) {
    $this->auth->addUser('taken@example.com', 'secret123');

    try {
        $call();
        $this->fail('Expected a FirebaseAuthException.');
    } catch (FirebaseAuthException $exception) {
        expect($exception->errorCode())->toBe($code);
    }
})->with([
    'bad email' => [fn () => FirebaseAuth::signInWithEmailAndPassword('nope', 'x'), 'invalid-email'],
    'wrong password' => [fn () => FirebaseAuth::signInWithEmailAndPassword('taken@example.com', 'wrong'), 'invalid-credential'],
    'unknown user' => [fn () => FirebaseAuth::signInWithEmailAndPassword('ghost@example.com', 'secret123'), 'invalid-credential'],
    'duplicate' => [fn () => FirebaseAuth::createUser('taken@example.com', 'secret123'), 'email-already-in-use'],
    'weak password' => [fn () => FirebaseAuth::createUser('new@example.com', '123'), 'weak-password'],
]);

it('signs in anonymously', function () {
    $user = FirebaseAuth::signInAnonymously();

    expect($user->isAnonymous)->toBeTrue()
        ->and($user->email)->toBeNull();
});

it('issues an ID token for the current user', function () {
    $this->auth->actingAs('ada@example.com');

    $token = FirebaseAuth::getIdToken(forceRefresh: true);
    $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    expect($claims['email'])->toBe('ada@example.com')
        ->and($claims['sub'])->toBe(FirebaseAuth::currentUser()->uid);
});

it('records password reset emails without revealing unknown accounts', function () {
    $this->auth->addUser('ada@example.com', 'secret123');

    FirebaseAuth::sendPasswordResetEmail('ada@example.com');
    FirebaseAuth::sendPasswordResetEmail('ghost@example.com');

    $this->auth->assertPasswordResetSent('ada@example.com');
    expect($this->auth->passwordResetsSent())->toBe(['ada@example.com']);
});

it('dispatches auth state events while listening', function () {
    Event::fake([AuthStateChanged::class]);

    FirebaseAuth::listen();
    FirebaseAuth::signInAnonymously();
    FirebaseAuth::signOut();
    FirebaseAuth::stopListening();
    FirebaseAuth::signInAnonymously();

    $states = Event::dispatched(AuthStateChanged::class)->map(fn (array $args) => $args[0]->signedIn())->all();

    expect($states)->toBe([false, true, false]);
});
