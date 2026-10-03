<?php

/**
 * Keeps nativephp.json, the Swift/Kotlin sources and the PHP classes in step:
 * every declared bridge function must exist natively on both platforms, every
 * declared event must be a real class whose constructor accepts the payload
 * keys the native code sends.
 */
function manifest(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/nativephp.json'), true, 512, JSON_THROW_ON_ERROR);
}

function nativeSource(string $platform): string
{
    $root = dirname(__DIR__, 2).'/resources/'.$platform;
    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array($file->getExtension(), ['swift', 'kt'], true)) {
            $source .= file_get_contents($file->getPathname());
        }
    }

    return $source;
}

it('declares a valid plugin manifest', function () {
    $manifest = manifest();
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($manifest['namespace'])->toBe('Firestore')
        ->and($composer['type'])->toBe('nativephp-plugin')
        ->and($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json')
        ->and($manifest['ios']['dependencies']['swift_packages'][0]['products'])->toBe(['FirebaseFirestore', 'FirebaseAuth'])
        ->and($manifest['android']['dependencies']['implementation'][0])->toStartWith('platform(com.google.firebase:firebase-bom:');
});

it('implements every bridge function on both platforms', function () {
    $swift = nativeSource('ios');
    $kotlin = nativeSource('android');

    foreach (manifest()['bridge_functions'] as $function) {
        [$iosEnum, $iosClass] = explode('.', $function['ios']);
        $kotlinParts = explode('.', $function['android']);
        $kotlinClass = array_pop($kotlinParts);
        $kotlinObject = array_pop($kotlinParts);

        expect(implode('.', $kotlinParts))->toBe('com.vitisstudio.plugins.firestore')
            ->and($swift)->toContain("enum {$iosEnum}")
            ->and($swift)->toMatch("/class {$iosClass}: BridgeFunction/")
            ->and($kotlin)->toContain("object {$kotlinObject}")
            ->and($kotlin)->toMatch("/class {$kotlinClass}\\(/");
    }
});

it('calls only bridge functions the manifest declares', function () {
    $declared = array_column(manifest()['bridge_functions'], 'name');
    $php = '';

    foreach (glob(dirname(__DIR__, 2).'/src/Bridge/*.php') as $file) {
        $php .= file_get_contents($file);
    }

    preg_match_all("/'((?:Firestore|FirebaseAuth)\\.[A-Za-z]+)'/", $php, $matches);

    expect(array_diff(array_unique($matches[1]), $declared))->toBe([]);
});

it('declares real event classes that the native payload keys can construct', function (string $event, array $nativeKeys) {
    expect(manifest()['events'])->toContain($event)
        ->and(class_exists($event))->toBeTrue();

    $parameters = array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionClass($event))->getConstructor()->getParameters());

    expect($parameters)->toBe($nativeKeys)
        ->and(nativeSource('ios'))->toContain(str_replace('\\', '\\\\', $event))
        ->and(nativeSource('android'))->toContain(str_replace('\\', '\\\\', $event));
})->with([
    [VitisStudio\NativeFirestore\Events\DocumentChanged::class, ['listenerId', 'path', 'id', 'exists', 'data', 'fromCache', 'hasPendingWrites', 'pendingServerTimestamps']],
    [VitisStudio\NativeFirestore\Events\QuerySnapshotChanged::class, ['listenerId', 'path', 'documents', 'changes', 'fromCache', 'hasPendingWrites']],
    [VitisStudio\NativeFirestore\Events\ListenerFailed::class, ['listenerId', 'code', 'message']],
    [VitisStudio\NativeFirestore\Events\WriteFailed::class, ['paths', 'code', 'message']],
    [VitisStudio\NativeFirestore\Events\AuthStateChanged::class, ['user']],
]);

it('stays generic: no app-specific names or Firebase project config in the package', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (str_contains($path, '/vendor/') || str_contains($path, '/.git/') || $path === __FILE__) {
            continue;
        }

        $name = $file->getFilename();
        expect($name)->not->toBe('GoogleService-Info.plist')
            ->and($name)->not->toBe('google-services.json');

        $contents = strtolower((string) file_get_contents($path));
        foreach (['omgravy', 'kidsomg', 'household', 'chore'] as $forbidden) {
            expect(str_contains($contents, $forbidden))->toBeFalse("[{$forbidden}] found in {$path}");
        }
    }
});
