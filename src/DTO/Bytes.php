<?php

namespace VitisStudio\NativeFirestore\DTO;

use InvalidArgumentException;

/**
 * Raw binary data stored as a Firestore Bytes/Blob value. Plain PHP strings
 * are always stored as Firestore strings, so binary needs this wrapper.
 */
final class Bytes
{
    public function __construct(public readonly string $raw) {}

    public static function fromBase64(string $base64): self
    {
        $raw = base64_decode($base64, true);

        if ($raw === false) {
            throw new InvalidArgumentException('Invalid base64 payload for Bytes.');
        }

        return new self($raw);
    }

    public function toBase64(): string
    {
        return base64_encode($this->raw);
    }

    /**
     * @return array{__type: 'bytes', base64: string}
     */
    public function toWire(): array
    {
        return ['__type' => 'bytes', 'base64' => $this->toBase64()];
    }
}
