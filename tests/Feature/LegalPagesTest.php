<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_privacy_policy_is_public_and_complete(): void
    {
        config(['legal.email' => 'privacy@whats-apis.com', 'legal.company' => 'KN Softic']);

        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('KN Softic')
            ->assertSee('privacy@whats-apis.com')
            ->assertSee('id="data-deletion"', false)   // Meta "data deletion instructions" anchor
            ->assertSee('WhatsApp Business Terms of Service');
    }

    public function test_terms_and_conditions_are_public(): void
    {
        $this->get('/terms-conditions')
            ->assertOk()
            ->assertSee('Terms &amp; Conditions', false)
            ->assertDontSee('&amp;amp;', false)
            ->assertSee('Acceptable use')
            ->assertSee('Governing law');
    }

    public function test_login_page_links_to_legal_pages(): void
    {
        $this->get('/login')
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false);
    }
}
