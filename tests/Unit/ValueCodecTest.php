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

it('accepts timestamps at the edges of the range Firestore stores', function () {
    expect((string) new Timestamp(Timestamp::MIN_SECONDS))->toBe('0001-01-01T00:00:00.000000Z')
        ->and((string) new Timestamp(Timestamp::MAX_SECONDS, 999_999_999))->toBe('9999-12-31T23:59:59.999999Z');
});

it('rejects timestamps outside the range Firestore stores', function (Closure $make) {
    expect($make)->toThrow(InvalidArgumentException::class, '0001-01-01T00:00:00Z and 9999-12-31T23:59:59Z');
})->with([
    'one second past the max' => [fn () => new Timestamp(Timestamp::MAX_SECONDS + 1)],
    'one second before the min' => [fn () => new Timestamp(Timestamp::MIN_SECONDS - 1)],
    'year 10000 DateTime' => [fn () => Timestamp::fromDateTime((new DateTimeImmutable('9999-12-31T23:59:59Z'))->modify('+1 second'))],
    'huge milliseconds' => [fn () => Timestamp::fromMilliseconds(PHP_INT_MAX)],
    'DateTime encoded as a value' => [fn () => ValueCodec::encode(new DateTimeImmutable('0000-06-01T00:00:00Z'))],
]);

it('rejects NaN GeoPoint coordinates', function () {
    expect(fn () => new GeoPoint(NAN, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new GeoPoint(0, NAN))->toThrow(InvalidArgumentException::class);
});

it('rejects sentinels anywhere beneath an array, at any depth', function (mixed $value) {
    expect(fn () => ValueCodec::encodeMap(['field' => $value]))->toThrow(InvalidArgumentException::class, 'inside an array');
})->with([
    'directly' => [[FieldValue::serverTimestamp()]],
    'in a map in an array' => [[['at' => FieldValue::serverTimestamp()]]],
    'deep in maps in an array' => [[['a' => ['b' => ['c' => FieldValue::increment(1)]]]]],
    'arrayUnion in a map in an array' => [[['tags' => FieldValue::arrayUnion('x')]]],
    'arrayRemove in a map in an array' => [[['tags' => FieldValue::arrayRemove('x')]]],
    'delete in a map in an array' => [[['gone' => FieldValue::delete()]]],
    'stdClass in an array' => [[(object) ['at' => FieldValue::serverTimestamp()]]],
]);

it('rejects sentinels inside arrayUnion/arrayRemove elements, however deep', function () {
    expect(fn () => ValueCodec::encode(FieldValue::arrayUnion(['at' => FieldValue::serverTimestamp()])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FieldValue::arrayRemove(FieldValue::increment(1))->toWire())->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValueCodec::encode(FieldValue::arrayUnion([1, 2])))->toThrow(InvalidArgumentException::class, 'arrays directly inside arrays');
});

it('rejects arrays directly inside arrays but allows them under maps', function () {
    expect(fn () => ValueCodec::encode([[1, 2]]))->toThrow(InvalidArgumentException::class, 'arrays directly inside arrays')
        ->and(ValueCodec::json(ValueCodec::encode([['xs' => [1, 2]]])))->toBe('[{"xs":[1,2]}]');
});

it('enforces where delete() may appear in writes', function () {
    expect(fn () => ValueCodec::encodeSetData(['x' => FieldValue::delete()], merge: false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValueCodec::encodeSetData(['m' => ['x' => FieldValue::delete()]], merge: false))->toThrow(InvalidArgumentException::class)
        ->and((array) ValueCodec::encodeSetData(['m' => ['x' => FieldValue::delete()]], merge: true))->toHaveKey('m')
        ->and((array) ValueCodec::encodeUpdateData(['m.x' => FieldValue::delete()]))->toHaveKey('m.x')
        ->and(fn () => ValueCodec::encodeUpdateData(['m' => ['x' => FieldValue::delete()]]))->toThrow(InvalidArgumentException::class, 'top level');
});

it('rejects reserved field names in writes but not in query operands', function () {
    expect(fn () => ValueCodec::encodeSetData(['__x__' => 1], merge: false))->toThrow(InvalidArgumentException::class, 'reserved')
        ->and(fn () => ValueCodec::encodeSetData(['list' => [['__x__' => 1]]], merge: true))->toThrow(InvalidArgumentException::class, 'reserved')
        ->and(fn () => ValueCodec::encodeUpdateData(['a.__name__' => 1]))->toThrow(InvalidArgumentException::class, 'reserved')
        ->and((array) ValueCodec::encodeArgument(['__x__' => 1]))->toBe(['__x__' => 1])
        ->and((array) ValueCodec::encodeSetData(['__x' => 1, 'x__' => 2], merge: false))->toBe(['__x' => 1, 'x__' => 2]);
});

it('rejects data nested deeper than Firestore allows', function () {
    $nest = fn (int $levels) => array_reduce(range(1, $levels), fn ($carry) => ['m' => $carry], 1);

    expect(ValueCodec::encodeMap($nest(19)))->toBeInstanceOf(stdClass::class)
        ->and(fn () => ValueCodec::encodeMap($nest(20)))->toThrow(InvalidArgumentException::class, 'nested too deeply');
});

it('rejects sentinels in query operands', function () {
    expect(fn () => ValueCodec::encodeArgument(FieldValue::serverTimestamp()))->toThrow(InvalidArgumentException::class, 'cannot be used in a query');
});
