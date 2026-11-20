<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Settings are cached statically per PHP process — never leak between tests.
        \App\Models\Setting::flushCache();

        parent::setUp();

        \App\Models\Setting::flushCache();

        // Views use @vite — tests never need compiled assets.
        $this->withoutVite();

        // A test must never reach the real Meta Graph API.
        Http::preventStrayRequests();
    }

    protected function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function agent(): User
    {
        return User::factory()->agent()->create();
    }
}
