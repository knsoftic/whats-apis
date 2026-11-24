<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

/** Mic button in chat → WhatsApp voice note (OGG/Opus). */
class VoiceNoteTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private WhatsAppAccount $account;

    private Conversation $conversation;

    private User $agent;

    /** Minimal OGG/Opus bytes (what Firefox records / ffmpeg produces). */
    private const OGG = "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->account = WhatsAppAccount::factory()->connected()->create();
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '923001112233']);
        $this->conversation = Conversation::factory()->withinWindow()->create(['contact_id' => $contact->id]);
        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->account);

        Http::fake([
            $this->graph($this->account->phone_number_id.'/media*') => Http::response(['id' => 'MEDIA-VOICE-1']),
            $this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOk('wamid.VOICE'),
        ]);
    }

    private function webm(): UploadedFile
    {
        $bytes = hex2bin('1A45DFA3')."\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02".str_repeat("\x00", 64);

        return UploadedFile::fake()->createWithContent('voice-note.webm', $bytes);
    }

    private function postVoice(UploadedFile $file)
    {
        return $this->actingAs($this->agent)->post("/chat/conversations/{$this->conversation->id}/media", ['file' => $file, 'voice' => '1'], ['Accept' => 'application/json']);
    }

    public function test_chrome_webm_recording_is_converted_to_ogg_and_sent_as_a_voice_note(): void
    {
        Process::fake(function (PendingProcess $process) {
            $cmd = $process->command;

            if (in_array('-i', $cmd, true)) {
                file_put_contents(end($cmd), self::OGG); // ffmpeg writes the .ogg
            }

            return Process::result('ok');
        });

        $this->postVoice($this->webm())->assertCreated()
            ->assertJsonPath('message.type', 'audio');

        Process::assertRan(fn (PendingProcess $p) => in_array('libopus', $p->command, true) && in_array('-ac', $p->command, true));

        $message = Message::firstOrFail();
        $this->assertTrue($message->content['voice']);
        $this->assertSame('🎤 Voice message', $message->previewText());
        $attachment = $message->attachments()->firstOrFail();
        $this->assertSame('audio/ogg', $attachment->mime_type);
        $this->assertStringEndsWith('.ogg', $attachment->path);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['type'] === 'audio' && $r['audio']['id'] === 'MEDIA-VOICE-1');
    }

    public function test_firefox_ogg_recording_is_sent_without_conversion(): void
    {
        Process::fake();

        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.ogg', self::OGG.str_repeat("\x00", 64)))->assertCreated()
            ->assertJsonPath('message.type', 'audio');

        Process::assertNothingRan();
        $this->assertTrue(Message::firstOrFail()->content['voice']);
    }

    public function test_clear_error_when_ffmpeg_is_missing_or_the_format_is_unknown(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        $this->postVoice($this->webm())->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'ffmpeg'));
        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.webm', 'just some text'))->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_chat_page_shows_the_mic_button(): void
    {
        $this->actingAs($this->agent)->get('/chat')->assertOk()->assertSee('Record a voice note')->assertSee('stopAndSendVoice', false);
    }
}
