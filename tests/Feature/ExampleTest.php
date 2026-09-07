<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The application is an API; the web root only points at the documentation.
 * Everything worth asserting about it lives in DocumentationTest.
 */
class ExampleTest extends TestCase
{
    public function test_the_application_boots_and_serves_the_web_root(): void
    {
        $this->get('/')->assertRedirect('/docs');
    }
}
