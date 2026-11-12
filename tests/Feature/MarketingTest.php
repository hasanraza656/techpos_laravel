<?php

namespace Tests\Feature;

use App\Http\Controllers\MarketingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
    }

    public function test_public_pages_render_and_seo_routes_exist(): void
    {
        foreach (array_keys(MarketingController::PAGES) as $p) {
            $this->get($p === 'home' ? '/' : '/'.$p)->assertOk()->assertSee('rel="canonical"', false)->assertSee('Tech POS');
        }
        $this->get('/sitemap.xml')->assertOk()->assertSee('/pricing');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /api/');
    }

    public function test_contact_capture_validates_consent_spam_and_attribution(): void
    {
        $this->from('/contact')->post('/leads', ['name' => 'Owner', 'email' => 'owner@example.test', 'type' => 'contact', 'message' => 'Interested in a store workspace.', 'utm_source' => 'newsletter'])->assertRedirect('/contact')->assertSessionHas('success');
        $this->assertDatabaseHas('leads', ['email' => 'owner@example.test', 'type' => 'contact']);
        $this->post('/leads', ['email' => 'x@example.test', 'type' => 'newsletter'])->assertSessionHasErrors('newsletter_consent');
        $this->post('/leads', ['email' => 'x@example.test', 'type' => 'newsletter', 'newsletter_consent' => 1, 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->assertDatabaseCount('leads', 1);
    }
}
