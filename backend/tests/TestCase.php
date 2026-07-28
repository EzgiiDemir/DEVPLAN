<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The real frontend always sends this, and Sanctum's
        // EnsureFrontendRequestsAreStateful only attaches session handling
        // (needed by register/login's session()->regenerate()) for requests
        // whose Origin matches a configured stateful domain. Without it,
        // every feature test hitting an auth route would 500.
        $this->withHeader('Origin', 'http://localhost:3000');
    }
}
