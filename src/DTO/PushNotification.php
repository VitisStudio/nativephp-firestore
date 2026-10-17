<?php

namespace VitisStudio\NativeFirestore\DTO;

/**
 * A push notification as the phone received it: what was shown, and the
 * sender's `data` keys (always strings when sent through FCM).
 */
final class PushNotification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $body = null,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $wire
     */
    public static function fromArray(array $wire): self
    {
        $title = $wire['title'] ?? null;
        $body = $wire['body'] ?? null;

        return new self(
            is_string($title) && $title !== '' ? $title : null,
            is_string($body) && $body !== '' ? $body : null,
            is_array($wire['data'] ?? null) ? $wire['data'] : [],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
