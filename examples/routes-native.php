<?php

// routes/native.php (or wherever your app registers native routes)

use App\NativeComponents\NotesScreen;
use Illuminate\Support\Facades\Route;

Route::native('/notes', NotesScreen::class);
