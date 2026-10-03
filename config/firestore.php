<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase platform config files
    |--------------------------------------------------------------------------
    |
    | The files you download from the Firebase console. At build time the
    | plugin's copy_assets hook copies them into the native project, where the
    | native code reads them at runtime. Missing files are not an error: the
    | explicit options below (or an already-configured FirebaseApp from
    | another plugin) are used instead.
    |
    */

    'ios' => [
        'config_path' => env('FIREBASE_IOS_CONFIG_PATH', base_path('GoogleService-Info.plist')),
    ],

    'android' => [
        'config_path' => env('FIREBASE_ANDROID_CONFIG_PATH', base_path('google-services.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit Firebase options
    |--------------------------------------------------------------------------
    |
    | Used when no FirebaseApp exists yet. These take priority over the config
    | files, so the same build can point at a different project without
    | swapping files. A project id starting with "demo-" needs nothing else:
    | it is meant for the Firebase Emulator Suite and works without a Firebase
    | account (placeholder app id / api key are filled in natively).
    |
    */

    'options' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'api_key' => env('FIREBASE_API_KEY'),
        'sender_id' => env('FIREBASE_SENDER_ID'),
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
        'ios_app_id' => env('FIREBASE_IOS_APP_ID', env('FIREBASE_APP_ID')),
        'android_app_id' => env('FIREBASE_ANDROID_APP_ID', env('FIREBASE_APP_ID')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firestore database
    |--------------------------------------------------------------------------
    */

    'database' => env('FIRESTORE_DATABASE', '(default)'),

    /*
    |--------------------------------------------------------------------------
    | Emulator Suite
    |--------------------------------------------------------------------------
    |
    | Leave a host empty to use the platform default for reaching the
    | development machine: 127.0.0.1 from the iOS simulator, 10.0.2.2 from the
    | Android emulator. Physical devices need your machine's LAN address.
    |
    */

    'emulator' => [
        'enabled' => (bool) env('FIREBASE_EMULATOR', false),
        'firestore' => [
            'host' => env('FIRESTORE_EMULATOR_HOST'),
            'port' => (int) env('FIRESTORE_EMULATOR_PORT', 8080),
        ],
        'auth' => [
            'host' => env('FIREBASE_AUTH_EMULATOR_HOST'),
            'port' => (int) env('FIREBASE_AUTH_EMULATOR_PORT', 9099),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline persistence
    |--------------------------------------------------------------------------
    |
    | On by default. cache_size_bytes of -1 means unlimited; the native SDKs
    | require at least 1 MB otherwise.
    |
    */

    'persistence' => [
        'enabled' => (bool) env('FIRESTORE_PERSISTENCE', true),
        'cache_size_bytes' => (int) env('FIRESTORE_CACHE_SIZE_BYTES', 100 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    |
    | timeout: seconds a blocking bridge call waits for the SDK before failing
    | with "deadline-exceeded".
    |
    | wait_for_server: false returns from writes as soon as the SDK has
    | accepted them locally (the offline-first default, so writes never stall
    | while offline); failures that the server reports later arrive as a
    | WriteFailed event. true blocks until the backend acknowledges the write.
    |
    | server_timestamps: how pending serverTimestamp() values read back before
    | the server confirms them: "none" (null), "estimate" or "previous".
    |
    */

    'timeout' => (float) env('FIRESTORE_TIMEOUT', 15),

    'wait_for_server' => (bool) env('FIRESTORE_WAIT_FOR_SERVER', false),

    'server_timestamps' => env('FIRESTORE_SERVER_TIMESTAMPS', 'none'),

    'transaction_max_attempts' => (int) env('FIRESTORE_TRANSACTION_MAX_ATTEMPTS', 5),

];
