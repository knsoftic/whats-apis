<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\ContactMessage;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\LandingContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @array_map('unlink', glob(public_path('uploads/landing/landing-test-*')) ?: []);
        parent::tearDown();
    }

    private function save(array $changes): void
    {
        $content = array_replace_recursive(LandingContent::get(), $changes);

        // Lists are replaced as a whole (not merged by index).
        foreach ($changes as $section => $values) {
            if (isset($values['items'])) {
                $content[$section]['items'] = $values['items'];
            }
        }

        $this->actingAs($this->superAdmin())
            ->put('/settings/landing', ['content' => json_encode($content)])
            ->assertRedirect('/settings/landing');

        auth()->logout();
    }

    public function test_guest_sees_landing_page_with_login_and_register_buttons(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('All your WhatsApp Business numbers in one smart inbox')
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertSee('>Login<', false)
            ->assertSee('>Register<', false)
            ->assertDontSee('>Dashboard<', false);
    }

    public function test_register_button_hidden_when_registration_is_off(): void
    {
        Setting::put(RegisteredUserController::SETTING, '0');

        $this->get('/')->assertOk()->assertSee('>Login<', false)->assertDontSee('>Register<', false);
    }

    public function test_logged_in_user_sees_dashboard_button(): void
    {
        $this->actingAs($this->agent())->get('/')
            ->assertOk()
            ->assertSee('>Dashboard<', false)
            ->assertDontSee('>Register<', false);
    }

    public function test_active_plans_are_listed_in_pricing(): void
    {
        Plan::query()->delete();
        Plan::create(['name' => 'Gold Plan', 'slug' => 'gold', 'price' => 1500, 'currency' => 'PKR', 'billing_period' => 'monthly', 'is_active' => true]);
        Plan::create(['name' => 'Hidden Plan', 'slug' => 'hidden', 'price' => 10, 'currency' => 'PKR', 'billing_period' => 'monthly', 'is_active' => false]);

        $this->get('/')->assertSee('Gold Plan')->assertSee('PKR 1,500/month')->assertDontSee('Hidden Plan');
    }

    public function test_only_super_admin_can_edit_the_landing_page(): void
    {
        $this->get('/settings/landing')->assertRedirect('/login');
        $this->actingAs($this->admin())->get('/settings/landing')->assertForbidden();
        $this->actingAs($this->agent())->put('/settings/landing', ['content' => '{}'])->assertForbidden();
        $this->actingAs($this->admin())->get('/contact-messages')->assertForbidden();

        $this->actingAs($this->superAdmin())->get('/settings/landing')
            ->assertOk()
            ->assertSee('Landing page')
            ->assertSee('Media library');
    }

    public function test_admin_edits_show_on_the_landing_page(): void
    {
        $this->save([
            'hero' => ['title' => 'Grow faster with Acme', 'badge' => 'New in 2026', 'media_type' => 'youtube', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ'],
            'features' => ['items' => [['icon' => 'shield', 'title' => 'Super secure', 'text' => 'Encrypted everything']]],
            'faq' => ['items' => [['q' => 'Is there a trial?', 'a' => 'Yes, seven days.']]],
            'contact' => ['email' => 'hello@acme.test', 'whatsapp' => '+92 300 1234567', 'address' => 'Lahore, Pakistan'],
            'testimonials' => ['enabled' => false],
            'seo' => ['title' => 'Acme WhatsApp Inbox'],
        ]);

        $this->get('/')
            ->assertSee('Grow faster with Acme')
            ->assertSee('New in 2026')
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('Super secure')
            ->assertDontSee('Shared live inbox')
            ->assertSee('Is there a trial?')
            ->assertSee('mailto:hello@acme.test', false)
            ->assertSee('https://wa.me/923001234567', false)
            ->assertSee('Lahore, Pakistan')
            ->assertDontSee('Loved by growing businesses')
            ->assertSee('<title>Acme WhatsApp Inbox</title>', false);
    }

    public function test_disabled_sections_disappear_from_page_and_menu(): void
    {
        $this->save(['features' => ['enabled' => false], 'faq' => ['enabled' => false], 'contact' => ['enabled' => false]]);

        $this->get('/')
            ->assertDontSee('id="features"', false)
            ->assertDontSee('href="#faq"', false)
            ->assertDontSee('id="contact"', false)
            ->assertSee('id="how-it-works"', false);
    }

    public function test_html_and_unsafe_links_are_cleaned(): void
    {
        $this->save([
            'hero' => ['title' => '<script>alert(1)</script>Safe title', 'image' => 'javascript:alert(1)', 'media_type' => 'image', 'youtube' => 'not a link'],
            'features' => ['items' => [['icon' => 'evil"><script>', 'title' => '<img src=x onerror=alert(1)>Card', 'text' => 'ok']]],
            'contact' => ['email' => 'not-an-email', 'whatsapp' => '+92-300<b>12'],
        ]);

        $saved = LandingContent::get();
        $this->assertSame('alert(1)Safe title', $saved['hero']['title']);
        $this->assertSame('', $saved['hero']['image']);
        $this->assertSame('', $saved['hero']['youtube']);
        $this->assertSame('check', $saved['features']['items'][0]['icon']);
        $this->assertSame('Card', $saved['features']['items'][0]['title']);
        $this->assertSame('', $saved['contact']['email']);
        $this->assertSame('+9230012', $saved['contact']['whatsapp']);

        $this->get('/')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('javascript:alert', false);
    }

    public function test_invalid_links_give_a_warning(): void
    {
        $content = LandingContent::get();
        $content['video']['youtube'] = 'https://vimeo.com/123';

        $this->actingAs($this->superAdmin())
            ->put('/settings/landing', ['content' => json_encode($content)])
            ->assertSessionHas('warning');
    }

    public function test_reset_restores_default_content(): void
    {
        $this->save(['hero' => ['title' => 'Temporary title']]);
        $this->get('/')->assertSee('Temporary title');

        $this->actingAs($this->superAdmin())->post('/settings/landing/reset')->assertRedirect('/settings/landing');
        auth()->logout();

        $this->get('/')->assertDontSee('Temporary title')->assertSee('All your WhatsApp Business numbers in one smart inbox');
    }

    public function test_image_and_video_upload_and_delete(): void
    {
        $super = $this->superAdmin();

        $image = $this->actingAs($super)->post('/settings/landing/media', [
            'type' => 'image', 'file' => UploadedFile::fake()->image('landing-test-hero.png', 800, 600),
        ])->assertOk()->json('file');

        $this->assertStringStartsWith('uploads/landing/landing-test-hero-', $image['path']);
        $this->assertFileExists(public_path($image['path']));
        $this->assertSame('image', $image['type']);

        // Use it as the hero image → shown on the page.
        $this->save(['hero' => ['media_type' => 'image', 'image' => $image['path']]]);
        $this->get('/')->assertSee($image['path'], false);

        // It is listed in the media library.
        $this->actingAs($super)->get('/settings/landing')->assertSee(basename($image['path']), false);

        // Video upload (MP4 bytes).
        $mp4 = UploadedFile::fake()->createWithContent('landing-test-clip.mp4', hex2bin('000000186674797069736f6d0000020069736f6d69736f32').hex2bin('00000008').'free'.str_repeat("\0", 64));
        $video = $this->actingAs($super)->post('/settings/landing/media', ['type' => 'video', 'file' => $mp4])->assertOk()->json('file');
        $this->assertSame('video', $video['type']);

        // Wrong types are rejected: SVG as image, PNG as video.
        $this->actingAs($super)->postJson('/settings/landing/media', [
            'type' => 'image', 'file' => UploadedFile::fake()->createWithContent('landing-test-x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertStatus(422);
        $this->actingAs($super)->postJson('/settings/landing/media', [
            'type' => 'video', 'file' => UploadedFile::fake()->image('landing-test-not-video.png'),
        ])->assertStatus(422);

        // Delete — only inside uploads/landing.
        $this->actingAs($super)->deleteJson('/settings/landing/media', ['path' => $image['path']])->assertOk();
        $this->assertFileDoesNotExist(public_path($image['path']));
        $this->actingAs($super)->deleteJson('/settings/landing/media', ['path' => 'uploads/landing/../../.env'])->assertStatus(422);
        $this->actingAs($super)->deleteJson('/settings/landing/media', ['path' => $video['path']])->assertOk();

        // Non-super-admin cannot upload.
        $this->actingAs($this->admin())->post('/settings/landing/media', ['type' => 'image', 'file' => UploadedFile::fake()->image('landing-test-a.png')])->assertForbidden();
    }

    public function test_contact_form_stores_message_and_emails_support(): void
    {
        Mail::fake();
        $this->save(['contact' => ['email' => 'support@acme.test']]);

        $this->post('/contact', [
            'name' => 'Ali Raza', 'email' => 'ali@example.test', 'phone' => '+92 300 1112233', 'message' => 'Please share the price list.',
        ])->assertRedirect(url('/').'#contact')->assertSessionHas('contact_sent');

        $this->assertDatabaseHas('contact_messages', ['email' => 'ali@example.test', 'name' => 'Ali Raza', 'read_at' => null]);
        $this->get('/')->assertSee('Message sent');

        // Admin inbox: listed, mark read, delete.
        $super = $this->superAdmin();
        $message = ContactMessage::firstOrFail();
        $this->actingAs($super)->get('/contact-messages')->assertOk()->assertSee('Ali Raza')->assertSee('Please share the price list.');
        $this->actingAs($super)->get('/dashboard')->assertSee('Contact Messages');
        $this->actingAs($super)->patch("/contact-messages/{$message->id}", ['read' => 1])->assertRedirect();
        $this->assertNotNull($message->fresh()->read_at);
        $this->actingAs($super)->delete("/contact-messages/{$message->id}")->assertRedirect();
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_contact_form_validation_and_honeypot(): void
    {
        $this->post('/contact', ['name' => '', 'email' => 'bad', 'message' => 'x'])
            ->assertRedirect(url('/').'#contact')
            ->assertSessionHasErrors(['name', 'email', 'message'], null, 'contact');

        // Bot fills the hidden field → looks successful, nothing stored.
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@spam.test', 'message' => 'Buy cheap stuff now', 'website' => 'http://spam.test'])
            ->assertSessionHas('contact_sent');
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_contact_form_is_closed_when_form_is_hidden(): void
    {
        $this->save(['contact' => ['show_form' => false]]);

        $this->get('/')->assertDontSee('action="'.route('landing.contact').'"', false);
        $this->post('/contact', ['name' => 'A', 'email' => 'a@b.test', 'message' => 'Hello there'])->assertNotFound();
    }

    public function test_youtube_id_parser(): void
    {
        $this->assertSame('dQw4w9WgXcQ', LandingContent::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10'));
        $this->assertSame('dQw4w9WgXcQ', LandingContent::youtubeId('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', LandingContent::youtubeId('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertNull(LandingContent::youtubeId('https://vimeo.com/123456'));
    }
}
