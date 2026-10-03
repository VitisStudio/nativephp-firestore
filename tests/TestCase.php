<?php

namespace VitisStudio\NativeFirestore\Tests;

use Native\Mobile\NativeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use VitisStudio\NativeFirestore\Bridge\NativeBridge;
use VitisStudio\NativeFirestore\FirestoreServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        NativeBridge::resetConfiguredState();
    }

    protected function getPackageProviders($app): array
    {
        return [NativeServiceProvider::class, FirestoreServiceProvider::class];
    }
}
