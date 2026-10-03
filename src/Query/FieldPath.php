<?php

namespace VitisStudio\NativeFirestore\Query;

final class FieldPath
{
    /** The special field that refers to a document's id/path in where() and orderBy(). */
    public const DOCUMENT_ID = '__name__';

    public static function documentId(): string
    {
        return self::DOCUMENT_ID;
    }
}
