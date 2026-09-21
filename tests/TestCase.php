<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStoragePath = realpath(sys_get_temp_dir()).'/orbit-tests-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->testStoragePath);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testStoragePath);
        parent::tearDown();
    }
}
