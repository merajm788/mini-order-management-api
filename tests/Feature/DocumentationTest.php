<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    #[Test]
    public function the_root_url_redirects_to_the_api_documentation(): void
    {
        $this->get('/')->assertRedirect('/docs');
    }

    #[Test]
    public function the_swagger_ui_page_renders(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('swagger-ui', escape: false);
    }

    #[Test]
    public function the_openapi_document_is_served_and_describes_every_endpoint(): void
    {
        $response = $this->get('/docs/openapi.yaml')->assertOk();

        $spec = $response->streamedContent();

        // A quick guard against the spec drifting away from routes/api.php.
        foreach ([
            '/register', '/login', '/logout', '/me',
            '/products', '/products/{id}',
            '/orders', '/orders/{id}', '/orders/{id}/cancel',
        ] as $path) {
            $this->assertStringContainsString("  {$path}:", $spec);
        }
    }

    #[Test]
    public function the_health_check_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
