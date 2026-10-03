<?php

namespace VitisStudio\NativeFirestore\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * copy_assets hook: copies the app's own Firebase config file into the native
 * project under a plugin-specific name, so it can never collide with a file
 * another Firebase plugin installs. A missing file is fine — explicit
 * options or an existing FirebaseApp are used instead.
 */
class CopyFirebaseConfigCommand extends NativePluginHookCommand
{
    public const IOS_DESTINATION = 'NativePHP/NativeFirestore-GoogleService-Info.plist';

    public const ANDROID_DESTINATION = 'app/src/main/assets/nativephp-firestore/google-services.json';

    protected $signature = 'nativephp:firestore:copy-config';

    protected $description = 'Copy the Firebase config file into the native project (Firestore plugin build hook)';

    public function handle(): int
    {
        if ($this->isIos()) {
            return $this->sync((string) config('firestore.ios.config_path'), self::IOS_DESTINATION, 'GoogleService-Info.plist');
        }

        if ($this->isAndroid()) {
            return $this->sync((string) config('firestore.android.config_path'), self::ANDROID_DESTINATION, 'google-services.json');
        }

        return self::SUCCESS;
    }

    private function sync(string $source, string $relativeDestination, string $label): int
    {
        $destination = rtrim($this->buildPath(), '/').'/'.$relativeDestination;

        if ($source === '' || ! is_file($source)) {
            // Never ship a stale copy from an earlier build.
            if (is_file($destination)) {
                @unlink($destination);
            }

            $this->line("Firestore: no {$label} at [{$source}]; relying on explicit FIREBASE_* options or an existing FirebaseApp.");

            return self::SUCCESS;
        }

        if (! $this->looksValid($source, $label)) {
            $this->error("Firestore: [{$source}] does not look like a valid {$label}.");

            return self::FAILURE;
        }

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        if (! copy($source, $destination)) {
            $this->error("Firestore: could not copy [{$source}] to [{$destination}].");

            return self::FAILURE;
        }

        $this->info("Firestore: copied {$label} into the native project.");

        return self::SUCCESS;
    }

    private function looksValid(string $source, string $label): bool
    {
        $contents = (string) file_get_contents($source);

        return str_ends_with($label, '.json')
            ? is_array(json_decode($contents, true)) && str_contains($contents, 'project_info')
            : str_contains($contents, '<plist') && str_contains($contents, 'GOOGLE_APP_ID');
    }
}
