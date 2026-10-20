<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Files the app keeps on the private disk (contract and receipt PDFs) must never land in the real storage folder.
        Storage::fake('local');
    }
}
