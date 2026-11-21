<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Chart PNGs and previews written by tests go here, never into storage/app/reports.
        config(['report.reports_path' => $this->reportsPath()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->reportsPath());

        parent::tearDown();
    }

    protected function reportsPath(string $sub = ''): string
    {
        return storage_path('framework/testing/reports').($sub === '' ? '' : '/'.ltrim($sub, '/'));
    }
}