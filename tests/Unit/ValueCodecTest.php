<?php

use Carbon\CarbonImmutable;
use VitisStudio\NativeFirestore\DTO\Bytes;
use VitisStudio\NativeFirestore\DTO\GeoPoint;
use VitisStudio\NativeFirestore\DTO\Timestamp;
use VitisStudio\NativeFirestore\FieldValue;
use VitisStudio\NativeFirestore\Query\DocumentReference;
use VitisStudio\NativeFirestore\Support\ValueCodec;

it('passes JSON-native scalars through', function () {
    expect(ValueCodec::encode(null))->toBeNull()
        ->and(ValueCodec::encode(true))->toBeTrue()
        ->and(ValueCodec::encode(42))->toBe(42)
        ->and(ValueCodec::encode('hi'))->toBe('hi')
        ->and(ValueCodec::encode(1.5))->toBe(1.5);
});

it('keeps whole floats as doubles on the wire', function () {
    expect(ValueCodec::json(['price' => 10.0]))->toBe('{"price":10.0}');
});

it('tags Firestore types', function () {
    $encoded = ValueCodec::encode([
        'at' => new Timestamp(1700000000, 5),
        'where' => new GeoPoint(51.5, -0.12),
        'owner' => new DocumentReference('users/u1'),
        'blob' => new Bytes("\x00\x01"),
        'nan' => NAN,
        'inf' => -INF,
    ]);

    expect((array) $encoded)->toBe([
        'at' => ['__type' => 'timestamp', 'seconds' => 1700000000, 'nanoseconds' => 5],
        'where' => ['__type' => 'geopoint', 'latitude' => 51.5, 'longitude' => -0.12],
        'owner' => ['__type' => 'reference', 'path' => 'users/u1'],
        'blob' => ['__type' => 'bytes', 'base64' => 'AAE='],
        'nan' => ['__type' => 'double', 'value' => 'NaN'],
        'inf' => ['__type' => 'double', 'value' => '-Infinity'],
    ]);
});

it('converts DateTime values to timestamps with microsecond precision', function () {
    $wire = ValueCodec::encode(CarbonImmutable::parse('2026-01-02 03:04:05.123456', 'UTC'));

    expect($wire)->toBe(['__type' => 'timestamp', 'seconds' => 1767323045, 'nanoseconds' => 123456000]);
});

it('encodes field value sentinels', function () {
    expect(ValueCodec::encode(FieldValue::serverTimestamp()))->toBe(['__type' => 'fieldvalue', 'op' => 'serverTimestamp'])
        ->and(ValueCodec::encode(FieldValue::increment(2)))->toBe(['__type' => 'fieldvalue', 'op' => 'increment', 'value' => 2])
        ->and(ValueCodec::encode(FieldValue::arrayUnion('a', new Timestamp(1))))->toBe([
            '__type' => 'fieldvalue', 'op' => 'arrayUnion', 'value' => ['a', ['__type' => 'timestamp', 'seconds' => 1, 'nanoseconds' => 0]],
        ])
        ->and(ValueCodec::encode(FieldValue::delete()))->toBe(['__type' => 'fieldvalue', 'op' => 'delete']);
});

it('always encodes document data as a JSON object', function () {
    expect(ValueCodec::json(ValueCodec::encodeMap([])))->toBe('{}')
        ->and(ValueCodec::json(ValueCodec::encodeMap(['tags' => [], 'meta' => new stdClass])))->toBe('{"tags":[],"meta":{}}');
});

it('rejects the reserved __type field name and unsupported objects', function () {
    expect(fn () => ValueCodec::encodeMap(['__type' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValueCodec::encode(new ArrayObject))->toThrow(InvalidArgumentException::class);
});

it('decodes tagged values back into DTOs', function () {
    $decoded = ValueCodec::decodeMap([
        'at' => ['__type' => 'timestamp', 'seconds' => 10, 'nanoseconds' => 0],
        'where' => ['__type' => 'geopoint', 'latitude' => 1, 'longitude' => 2],
        'owner' => ['__type' => 'reference', 'path' => 'users/u1'],
        'blob' => ['__type' => 'bytes', 'base64' => 'AAE='],
        'nan' => ['__type' => 'double', 'value' => 'NaN'],
        'nested' => ['list' => [['__type' => 'timestamp', 'seconds' => 1, 'nanoseconds' => 0]]],
    ]);

    expect($decoded['at'])->toBeInstanceOf(Timestamp::class)
        ->and($decoded['where'])->toEqual(new GeoPoint(1, 2))
        ->and($decoded['owner'])->toBeInstanceOf(DocumentReference::class)
        ->and($decoded['owner']->path())->toBe('users/u1')
        ->and($decoded['blob']->raw)->toBe("\x00\x01")
        ->and(is_nan($decoded['nan']))->toBeTrue()
        ->and($decoded['nested']['list'][0])->toBeInstanceOf(Timestamp::class);
});

it('round-trips timestamps through milliseconds and DateTime', function () {
    $timestamp = Timestamp::fromMilliseconds(-1);

    expect($timestamp->seconds)->toBe(-1)
        ->and($timestamp->nanoseconds)->toBe(999_000_000)
        ->and($timestamp->toMilliseconds())->toBe(-1)
        ->and((string) new Timestamp(0, 1_500))->toBe('1970-01-01T00:00:00.000001Z');
});
