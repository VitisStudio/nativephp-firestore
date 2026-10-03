<?php

namespace VitisStudio\NativeFirestore\Bridge;

use JsonException;
use VitisStudio\NativeFirestore\Exceptions\FirebaseAuthException;
use VitisStudio\NativeFirestore\Exceptions\FirebaseException;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * Talks to the plugin's Swift/Kotlin functions through nativephp_call().
 *
 * Wire protocol: arguments travel JSON-encoded in a single "payload" string
 * (so nested maps arrive identically on iOS and Android); results come back
 * JSON-encoded in "result"; failures use NativePHP's
 * {"status": "error", "code", "message"} envelope with Firebase error codes.
 *
 * Before the first operation in a PHP process the bridge sends the app's
 * Firebase configuration with Firestore.Configure. Native code initialises
 * FirebaseApp once per app launch and treats later Configure calls as no-ops.
 */
class NativeBridge
{
    private const JUMP_HINT = 'Firestore ships native code, so it only runs in builds made with `php artisan native:run` '
        .'(Jump runs a prebuilt shell without custom plugins). In tests, use Firestore::fake() / FirebaseAuth::fake().';

    /** Survives container resets in NativePHP's persistent runtime. */
    private static bool $configured = false;

    /**
     * @param  array<string, mixed>  $config  the firestore.* config array
     */
    public function __construct(private readonly array $config) {}

    public function call(string $method, array $arguments = []): mixed
    {
        $this->ensureConfigured();

        return $this->invoke($method, $arguments);
    }

    /**
     * Initialise Firebase natively with this app's configuration. Safe to
     * call repeatedly; the native side configures only once per launch.
     *
     * @return array<string, mixed>
     */
    public function configure(): array
    {
        $status = (array) $this->invoke('Firestore.Configure', $this->configurePayload());
        self::$configured = true;

        return $status;
    }

    public function ensureConfigured(): void
    {
        if (! self::$configured) {
            $this->configure();
        }
    }

    public static function resetConfiguredState(): void
    {
        self::$configured = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function configurePayload(): array
    {
        $options = (array) ($this->config['options'] ?? []);
        $emulator = (array) ($this->config['emulator'] ?? []);
        $persistence = (array) ($this->config['persistence'] ?? []);

        return [
            'options' => [
                'projectId' => $options['project_id'] ?? null,
                'apiKey' => $options['api_key'] ?? null,
                'senderId' => $options['sender_id'] ?? null,
                'storageBucket' => $options['storage_bucket'] ?? null,
                'iosAppId' => $options['ios_app_id'] ?? null,
                'androidAppId' => $options['android_app_id'] ?? null,
            ],
            'database' => $this->config['database'] ?? '(default)',
            'emulator' => [
                'enabled' => (bool) ($emulator['enabled'] ?? false),
                'firestoreHost' => $emulator['firestore']['host'] ?? null,
                'firestorePort' => (int) ($emulator['firestore']['port'] ?? 8080),
                'authHost' => $emulator['auth']['host'] ?? null,
                'authPort' => (int) ($emulator['auth']['port'] ?? 9099),
            ],
            'persistence' => [
                'enabled' => (bool) ($persistence['enabled'] ?? true),
                'cacheSizeBytes' => (int) ($persistence['cache_size_bytes'] ?? 100 * 1024 * 1024),
            ],
            'serverTimestamps' => (string) ($this->config['server_timestamps'] ?? 'none'),
            'timeout' => (float) ($this->config['timeout'] ?? 15),
        ];
    }

    private function invoke(string $method, array $arguments): mixed
    {
        if (! function_exists('nativephp_call')) {
            throw $this->exception($method, 'The NativePHP bridge is not available. '.self::JUMP_HINT, FirebaseException::BRIDGE_UNAVAILABLE);
        }

        if (function_exists('nativephp_can') && ! nativephp_can($method)) {
            throw $this->exception($method, "Native function [{$method}] is not in this build. ".self::JUMP_HINT, FirebaseException::BRIDGE_UNAVAILABLE);
        }

        $raw = nativephp_call($method, ValueCodec::json(['payload' => ValueCodec::json((object) $arguments)]));

        if ($raw === null || $raw === '') {
            throw $this->exception($method, "Native function [{$method}] is not registered. ".self::JUMP_HINT, FirebaseException::BRIDGE_UNAVAILABLE);
        }

        try {
            $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->exception($method, "Unreadable response from [{$method}]: {$exception->getMessage()}", FirebaseException::UNKNOWN);
        }

        if (! is_array($response)) {
            return $response;
        }

        if (($response['status'] ?? null) === 'error') {
            throw $this->exception($method, (string) ($response['message'] ?? "Native call [{$method}] failed."), $this->normaliseCode((string) ($response['code'] ?? '')));
        }

        $result = $response['result'] ?? $response['data']['result'] ?? null;

        if (! is_string($result)) {
            return null;
        }

        return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    }

    private function normaliseCode(string $code): string
    {
        return match ($code) {
            'FUNCTION_NOT_FOUND', 'NO_DEVICE', 'SEND_FAILED' => FirebaseException::BRIDGE_UNAVAILABLE,
            'TIMEOUT' => FirestoreException::DEADLINE_EXCEEDED,
            '' => FirebaseException::UNKNOWN,
            default => str_replace('_', '-', strtolower($code)),
        };
    }

    private function exception(string $method, string $message, string $code): FirebaseException
    {
        return str_starts_with($method, 'FirebaseAuth.')
            ? new FirebaseAuthException($message, $code)
            : new FirestoreException($message, $code);
    }
}
