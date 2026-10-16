<?php

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

uses(BaseTestCase::class)
    ->in('Feature')
    ->beforeEach(function () {
        // Used by Laravel test suite compatibility when needed
        if (method_exists(RefreshDatabaseState::class, 'setUsingInMemoryDatabase')) {
            // no-op
        }
    });
