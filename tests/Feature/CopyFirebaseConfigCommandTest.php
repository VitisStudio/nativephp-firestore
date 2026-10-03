<?php

use Illuminate\Support\Facades\File;
use VitisStudio\NativeFirestore\Commands\CopyFirebaseConfigCommand;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nativephp-firestore-'.uniqid();
    File::ensureDirectoryExists($this->root.'/app');
    File::ensureDirectoryExists($this->root.'/build');
});

afterEach(fn () => File::deleteDirectory($this->root));

it('copies the iOS plist under a plugin-specific name', function () {
    File::put($this->root.'/app/GoogleService-Info.plist', '<?xml version="1.0"?><plist><dict><key>GOOGLE_APP_ID</key><string>1:1:ios:a</string></dict></plist>');
    config()->set('firestore.ios.config_path', $this->root.'/app/GoogleService-Info.plist');

    $this->artisan('nativephp:firestore:copy-config', ['--platform' => 'ios', '--build-path' => $this->root.'/build'])
        ->assertSuccessful();

    expect(File::exists($this->root.'/build/'.CopyFirebaseConfigCommand::IOS_DESTINATION))->toBeTrue();
});

it('copies google-services.json into Android assets', function () {
    File::put($this->root.'/app/google-services.json', json_encode(['project_info' => ['project_id' => 'p']]));
    config()->set('firestore.android.config_path', $this->root.'/app/google-services.json');

    $this->artisan('nativephp:firestore:copy-config', ['--platform' => 'android', '--build-path' => $this->root.'/build'])
        ->assertSuccessful();

    expect(File::json($this->root.'/build/'.CopyFirebaseConfigCommand::ANDROID_DESTINATION))
        ->toBe(['project_info' => ['project_id' => 'p']]);
});

it('succeeds without a config file and removes a stale copy', function () {
    $stale = $this->root.'/build/'.CopyFirebaseConfigCommand::IOS_DESTINATION;
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, 'old');
    config()->set('firestore.ios.config_path', $this->root.'/app/missing.plist');

    $this->artisan('nativephp:firestore:copy-config', ['--platform' => 'ios', '--build-path' => $this->root.'/build'])
        ->assertSuccessful();

    expect(File::exists($stale))->toBeFalse();
});

it('refuses a file that is not a Firebase config', function () {
    File::put($this->root.'/app/google-services.json', '{"hello": "world"}');
    config()->set('firestore.android.config_path', $this->root.'/app/google-services.json');

    $this->artisan('nativephp:firestore:copy-config', ['--platform' => 'android', '--build-path' => $this->root.'/build'])
        ->assertFailed();
});
