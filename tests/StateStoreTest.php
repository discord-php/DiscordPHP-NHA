<?php

declare(strict_types=1);

namespace Tests\NHA;

use NHATestCase;
use NHA\StateStore;

class StateStoreTest extends NHATestCase
{
    protected string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-state-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    public function testDefaultAgentIsNullWhenNoFileExists(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->getDefaultAgent());
    }

    public function testSetDefaultAgentPersistsToDisk(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42);

        $this->assertSame(42, $store->getDefaultAgent());
        $this->assertFileExists($this->path);
        $this->assertSame(['default_agent' => 42], json_decode(file_get_contents($this->path), true));
    }

    public function testStateIsReloadedFromExistingFile(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(7);

        $reloaded = new StateStore($this->path);

        $this->assertSame(7, $reloaded->getDefaultAgent());
    }

    public function testSetDefaultAgentOverwritesPreviousValue(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(1);
        $store->setDefaultAgent(2);

        $this->assertSame(2, $store->getDefaultAgent());
    }
}
