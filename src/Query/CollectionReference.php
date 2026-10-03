<?php

namespace VitisStudio\NativeFirestore\Query;

use VitisStudio\NativeFirestore\FirestoreClient;
use VitisStudio\NativeFirestore\Support\Path;

class CollectionReference extends Query
{
    public function __construct(string $path, ?FirestoreClient $client = null)
    {
        parent::__construct(Path::collection($path), false, $client);
    }

    public function id(): string
    {
        return Path::lastSegment($this->path);
    }

    /**
     * The document this subcollection lives under, or null at the root.
     */
    public function parent(): ?DocumentReference
    {
        $parent = Path::parent($this->path);

        return $parent === null ? null : new DocumentReference($parent, $this->client);
    }

    /**
     * A reference to a document in this collection; a new auto-id when no id
     * is given (nothing is written until you set it).
     */
    public function doc(?string $id = null): DocumentReference
    {
        return new DocumentReference($this->path.'/'.($id ?? Path::autoId()), $this->client);
    }

    /**
     * Create a document with an auto-generated id, exactly like the SDKs'
     * add(): the id is generated client-side, so this works offline.
     *
     * @param  array<string, mixed>  $data
     */
    public function add(array $data): DocumentReference
    {
        $reference = $this->doc();
        $reference->set($data);

        return $reference;
    }
}
