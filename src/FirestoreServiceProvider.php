<?php

namespace VitisStudio\NativeFirestore;

use Illuminate\Support\ServiceProvider;
use VitisStudio\NativeFirestore\Bridge\NativeAuthDriver;
use VitisStudio\NativeFirestore\Bridge\NativeBridge;
use VitisStudio\NativeFirestore\Bridge\NativeFirestoreDriver;
use VitisStudio\NativeFirestore\Commands\CopyFirebaseConfigCommand;

class FirestoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/firestore.php', 'firestore');

        $this->app->singleton(NativeBridge::class, fn ($app) => new NativeBridge((array) $app['config']->get('firestore', [])));

        $this->app->singleton('firestore', fn ($app) => new FirestoreClient(
            new NativeFirestoreDriver($app->make(NativeBridge::class)),
            (bool) $app['config']->get('firestore.wait_for_server', false),
            (int) $app['config']->get('firestore.transaction_max_attempts', 5),
        ));

        $this->app->singleton('firebase.auth', fn ($app) => new FirebaseAuthClient(
            new NativeAuthDriver($app->make(NativeBridge::class)),
        ));

        $this->app->alias('firestore', FirestoreClient::class);
        $this->app->alias('firebase.auth', FirebaseAuthClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/firestore.php' => config_path('firestore.php'),
            ], 'firestore-config');

            $this->commands([
                CopyFirebaseConfigCommand::class,
            ]);
        }
    }
}
