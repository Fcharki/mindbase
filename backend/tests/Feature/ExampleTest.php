<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_workspace_discovery_requires_authentication(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    }
}
