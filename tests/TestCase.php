<?php

namespace Tests;

use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed permissions, roles and settings for every RefreshDatabase test. */
    protected bool $seed = true;

    protected string $seeder = CoreSeeder::class;
}
