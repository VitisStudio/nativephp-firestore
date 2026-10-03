<?php

namespace VitisStudio\NativeFirestore\Support;

use InvalidArgumentException;

/**
 * Slash-separated Firestore resource paths: collections have an odd number of
 * segments ("notes", "users/u1/notes"), documents an even number.
 */
final class Path
{
    private const AUTO_ID_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            throw new InvalidArgumentException('A Firestore path cannot be empty.');
        }

        $segments = explode('/', $trimmed);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Invalid Firestore path [{$path}].");
            }
        }

        return $segments;
    }

    public static function collection(string $path): string
    {
        $segments = self::segments($path);

        if (count($segments) % 2 === 0) {
            throw new InvalidArgumentException("[{$path}] is a document path; a collection path has an odd number of segments.");
        }

        return implode('/', $segments);
    }

    public static function document(string $path): string
    {
        $segments = self::segments($path);

        if (count($segments) % 2 !== 0) {
            throw new InvalidArgumentException("[{$path}] is a collection path; a document path has an even number of segments.");
        }

        return implode('/', $segments);
    }

    public static function collectionId(string $collectionId): string
    {
        if ($collectionId === '' || str_contains($collectionId, '/')) {
            throw new InvalidArgumentException("Invalid collection id [{$collectionId}] for a collection group query.");
        }

        return $collectionId;
    }

    public static function parent(string $path): ?string
    {
        $segments = self::segments($path);
        array_pop($segments);

        return $segments === [] ? null : implode('/', $segments);
    }

    public static function lastSegment(string $path): string
    {
        $segments = self::segments($path);

        return end($segments);
    }

    /**
     * A 20-character id with the same alphabet the Firebase SDKs use.
     */
    public static function autoId(): string
    {
        $id = '';
        $max = strlen(self::AUTO_ID_ALPHABET) - 1;

        for ($i = 0; $i < 20; $i++) {
            $id .= self::AUTO_ID_ALPHABET[random_int(0, $max)];
        }

        return $id;
    }
}
