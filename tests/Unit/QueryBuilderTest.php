<?php

use VitisStudio\NativeFirestore\DTO\DocumentSnapshot;
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\Query\Filter;

it('builds the wire form of a query', function () {
    $query = Firestore::collection('notes')
        ->where('status', 'open')
        ->where('priority', '>=', 2)
        ->whereIn('tag', ['a', 'b'])
        ->orderBy('priority', 'desc')
        ->limit(10)
        ->startAfter(5);

    expect($query->toWire())->toBe([
        'path' => 'notes',
        'collectionGroup' => false,
        'filters' => [
            ['field' => 'status', 'op' => '==', 'value' => 'open'],
            ['field' => 'priority', 'op' => '>=', 'value' => 2],
            ['field' => 'tag', 'op' => 'in', 'value' => ['a', 'b']],
        ],
        'orderBy' => [['field' => 'priority', 'direction' => 'desc']],
        'limit' => 10,
        'limitType' => 'first',
        'startAt' => ['values' => [5], 'inclusive' => false],
        'endAt' => null,
    ]);
});

it('is immutable', function () {
    $base = Firestore::collection('notes');
    $filtered = $base->where('done', false);

    expect($base->toWire()['filters'])->toBe([])
        ->and($filtered->toWire()['filters'])->toHaveCount(1);
});

it('supports every Firestore operator and normalises aliases', function (string $operator, string $expected, mixed $value) {
    $wire = Firestore::collection('c')->where('f', $operator, $value)->toWire();

    expect($wire['filters'][0]['op'])->toBe($expected);
})->with([
    ['==', '==', 1], ['=', '==', 1], ['!=', '!=', 1], ['<>', '!=', 1],
    ['<', '<', 1], ['<=', '<=', 1], ['>', '>', 1], ['>=', '>=', 1],
    ['array-contains', 'array-contains', 1],
    ['array-contains-any', 'array-contains-any', [1]],
    ['in', 'in', [1]],
    ['not-in', 'not-in', [1]],
]);

it('rejects unknown operators and malformed list operands', function () {
    expect(fn () => Firestore::collection('c')->where('f', 'like', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::collection('c')->where('f', 'in', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::collection('c')->where('f', 'not-in', []))->toThrow(InvalidArgumentException::class);
});

it('serialises composite OR / AND filters', function () {
    $wire = Firestore::collection('c')
        ->where(Filter::or(Filter::where('a', '==', 1), Filter::and(Filter::where('b', '>', 2), Filter::where('c', '<', 3))))
        ->toWire();

    expect($wire['filters'][0])->toBe([
        'composite' => 'or',
        'filters' => [
            ['field' => 'a', 'op' => '==', 'value' => 1],
            ['composite' => 'and', 'filters' => [
                ['field' => 'b', 'op' => '>', 'value' => 2],
                ['field' => 'c', 'op' => '<', 'value' => 3],
            ]],
        ],
    ]);
});

it('requires an orderBy for limitToLast', function () {
    expect(fn () => Firestore::collection('c')->limitToLast(2)->toWire())->toThrow(InvalidArgumentException::class);

    expect(Firestore::collection('c')->orderBy('n')->limitToLast(2)->toWire())
        ->toMatchArray(['limit' => 2, 'limitType' => 'last']);
});

it('uses a document snapshot as a cursor by path', function () {
    $snapshot = DocumentSnapshot::fromArray(['path' => 'c/d1', 'exists' => true, 'data' => ['n' => 1]]);

    expect(Firestore::collection('c')->orderBy('n')->startAt($snapshot)->endBefore(9)->toWire())->toMatchArray([
        'startAt' => ['document' => 'c/d1', 'inclusive' => true],
        'endAt' => ['values' => [9], 'inclusive' => false],
    ]);
});

it('validates paths', function () {
    expect(fn () => Firestore::collection('users/u1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::doc('users'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::doc('users//x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::collectionGroup('a/b'))->toThrow(InvalidArgumentException::class);
});

it('navigates references', function () {
    $doc = Firestore::collection('users')->doc('u1')->collection('notes')->doc('n1');

    expect($doc->path())->toBe('users/u1/notes/n1')
        ->and($doc->id())->toBe('n1')
        ->and($doc->parent()->path())->toBe('users/u1/notes')
        ->and($doc->parent()->parent()->path())->toBe('users/u1')
        ->and(Firestore::collection('users')->parent())->toBeNull()
        ->and(Firestore::collection('users')->doc()->id())->toMatch('/^[A-Za-z0-9]{20}$/');
});

it('rejects a document cursor that lacks a field the query is ordered by', function () {
    $snapshot = DocumentSnapshot::fromArray(['path' => 'c/d1', 'exists' => true, 'data' => ['n' => 1, 'gone' => null]]);

    expect(fn () => Firestore::collection('c')->orderBy('m')->startAfter($snapshot)->toWire())
        ->toThrow(InvalidArgumentException::class, '[m]')
        ->and(fn () => Firestore::collection('c')->where('m', '>', 0)->endAt($snapshot)->toWire())
        ->toThrow(InvalidArgumentException::class, '[m]')
        ->and(fn () => Firestore::collection('c')->startAt($snapshot)->orderBy('meta.level')->toWire())
        ->toThrow(InvalidArgumentException::class, '[meta.level]');

    expect(Firestore::collection('c')->orderBy('n')->orderBy('gone')->where('n', '!=', 3)->startAt($snapshot)->toWire()['startAt'])
        ->toBe(['document' => 'c/d1', 'inclusive' => true]);
});

it('rejects more cursor values than orderBy clauses', function () {
    expect(fn () => Firestore::collection('c')->startAt(1)->toWire())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::collection('c')->orderBy('a')->endBefore(1, 2)->toWire())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Firestore::collection('c')->where('a', '>', 0)->startAt(1)->toWire())->toThrow(InvalidArgumentException::class);
});

it('rejects filter combinations the native SDKs refuse', function (Closure $build) {
    expect(fn () => $build(Firestore::collection('c'))->toWire())->toThrow(InvalidArgumentException::class);
})->with([
    'two != filters' => [fn ($q) => $q->where('a', '!=', 1)->where('b', '!=', 2)],
    'two not-in filters' => [fn ($q) => $q->whereNotIn('a', [1])->whereNotIn('b', [2])],
    'not-in with !=' => [fn ($q) => $q->whereNotIn('a', [1])->where('b', '!=', 2)],
    'not-in with in' => [fn ($q) => $q->whereNotIn('a', [1])->whereIn('b', [2])],
    'not-in with OR' => [fn ($q) => $q->whereNotIn('a', [1])->where(Filter::or(Filter::where('b', '==', 1), Filter::where('c', '==', 2)))],
    'two array-contains filters' => [fn ($q) => $q->whereArrayContains('a', 1)->whereArrayContainsAny('b', [2])],
    'array-contains on the document id' => [fn ($q) => $q->whereArrayContains('__name__', 'x')],
    'nested arrays in array-contains-any' => [fn ($q) => $q->whereArrayContainsAny('a', [[1, 2]])],
    'nested != inside an AND' => [fn ($q) => $q->where(Filter::and(Filter::where('a', '!=', 1), Filter::where('b', '!=', 2)))],
]);

it('allows maps in array-contains-any and arrays in in()', function () {
    expect(Firestore::collection('c')->whereArrayContainsAny('a', [['k' => 1]])->toWire()['filters'])->toHaveCount(1)
        ->and(Firestore::collection('c')->whereIn('a', [[1, 2]])->toWire()['filters'])->toHaveCount(1);
});
