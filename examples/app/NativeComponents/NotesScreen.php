<?php

namespace App\NativeComponents;

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\NativeComponent;
use VitisStudio\NativeFirestore\DTO\QuerySnapshot;
use VitisStudio\NativeFirestore\Events\ListenerFailed;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Exceptions\FirebaseException;
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\FieldValue;

/**
 * A realtime, offline-capable notes list: the listener keeps $notes in sync
 * with Firestore, writes return immediately and sync when online.
 */
class NotesScreen extends NativeComponent
{
    /** @var list<array<string, mixed>> */
    public array $notes = [];

    public string $draft = '';

    public bool $offline = false;

    public ?string $error = null;

    public ?string $listenerId = null;

    public function mount(): void
    {
        try {
            $this->listenerId = Firestore::collection('notes')
                ->orderBy('createdAt', 'desc')
                ->limit(100)
                ->listen();

            $listenerId = $this->listenerId;
            $this->registerCleanup(fn () => Firestore::stop($listenerId));
        } catch (FirebaseException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     */
    #[On(QuerySnapshotChanged::class)]
    public function notesChanged(string $listenerId, array $documents, bool $fromCache): void
    {
        if ($listenerId !== $this->listenerId) {
            return;
        }

        $this->notes = QuerySnapshot::fromArray(compact('documents'))->toArray();
        $this->offline = $fromCache;
    }

    #[On(ListenerFailed::class)]
    public function listenerFailed(string $listenerId, string $code, string $message): void
    {
        if ($listenerId === $this->listenerId) {
            $this->error = "{$code}: {$message}";
        }
    }

    public function add(): void
    {
        $text = trim($this->draft);

        if ($text === '') {
            return;
        }

        Firestore::collection('notes')->add([
            'text' => $text,
            'done' => false,
            'createdAt' => FieldValue::serverTimestamp(),
        ]);

        $this->draft = '';
    }

    public function toggle(string $id): void
    {
        $note = collect($this->notes)->firstWhere('id', $id);

        Firestore::doc("notes/{$id}")->update(['done' => ! ($note['done'] ?? false)]);
    }

    public function remove(string $id): void
    {
        Firestore::doc("notes/{$id}")->delete();
    }

    public function render(): Element
    {
        $list = Column::make();

        foreach ($this->notes as $note) {
            $list->addChild(Row::make(
                Text::make(($note['done'] ? '✓ ' : '○ ').$note['text']),
                Button::make($note['done'] ? 'Undo' : 'Done')->onPress("toggle('{$note['id']}')"),
                Button::make('Delete')->onPress("remove('{$note['id']}')"),
            ));
        }

        return Column::make(
            Text::make('Notes'),
            $this->offline ? Text::make('Offline — changes will sync later') : Text::make(''),
            $this->error !== null ? Text::make($this->error) : Text::make(''),
            Row::make(
                TextInput::make()->onChange("__syncProperty('draft')"),
                Button::make('Add')->onPress('add'),
            ),
            ScrollView::make($list),
        );
    }
}
