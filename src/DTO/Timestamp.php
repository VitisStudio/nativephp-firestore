<?php

namespace VitisStudio\NativeFirestore\DTO;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A Firestore timestamp: nanosecond precision, UTC, independent of PHP's
 * microsecond DateTime.
 */
final class Timestamp implements JsonSerializable, Stringable
{
    /** 0001-01-01T00:00:00Z, the earliest instant Firestore can store. */
    public const MIN_SECONDS = -62_135_596_800;

    /** 9999-12-31T23:59:59Z, the latest whole second Firestore can store. */
    public const MAX_SECONDS = 253_402_300_799;

    public function __construct(
        public readonly int $seconds,
        public readonly int $nanoseconds = 0,
    ) {
        if ($nanoseconds < 0 || $nanoseconds > 999_999_999) {
            throw new InvalidArgumentException('Timestamp nanoseconds must be between 0 and 999,999,999.');
        }

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Timestamp seconds must fall between 0001-01-01T00:00:00Z and 9999-12-31T23:59:59Z.');
        }
    }

    public static function now(): self
    {
        return self::fromDateTime(CarbonImmutable::now());
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self($dateTime->getTimestamp(), (int) $dateTime->format('u') * 1000);
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        $seconds = intdiv($milliseconds, 1000);
        $remainder = $milliseconds % 1000;

        if ($remainder < 0) {
            $seconds--;
            $remainder += 1000;
        }

        return new self($seconds, $remainder * 1_000_000);
    }

    public function toDateTime(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->seconds)
            ->addMicroseconds(intdiv($this->nanoseconds, 1000));
    }

    public function toMilliseconds(): int
    {
        return $this->seconds * 1000 + intdiv($this->nanoseconds, 1_000_000);
    }

    public function compareTo(self $other): int
    {
        return [$this->seconds, $this->nanoseconds] <=> [$other->seconds, $other->nanoseconds];
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * @return array{__type: 'timestamp', seconds: int, nanoseconds: int}
     */
    public function toWire(): array
    {
        return ['__type' => 'timestamp', 'seconds' => $this->seconds, 'nanoseconds' => $this->nanoseconds];
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    public function __toString(): string
    {
        return $this->toDateTime()->format('Y-m-d\TH:i:s.u\Z');
    }
}
