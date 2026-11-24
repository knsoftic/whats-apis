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
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** Mic button in chat → WhatsApp voice note (OGG/Opus). */
class VoiceNoteTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

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

        // Mono OGG/Opus, shown as a voice message.
        Process::assertRan(fn (PendingProcess $p) => in_array('-ac', $p->command, true) && $p->command[array_search('-ac', $p->command, true) + 1] === '1');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['type'] === 'audio' && $r['audio'] === ['id' => 'MEDIA-VOICE-1', 'voice' => true]);
    }

    private function fakeFfmpeg(): void
    {
        Process::fake(function (PendingProcess $process) {
            $cmd = $process->command;
            if (in_array('-i', $cmd, true)) {
                file_put_contents(end($cmd), self::OGG); // ffmpeg writes the mono .ogg
            }

            return Process::result('ok');
        });
    }

    private function stereoOgg(): string
    {
        return str_replace("OpusHead\x01\x01", "OpusHead\x01\x02", self::OGG).str_repeat("\x00", 64);
    }

    public function test_firefox_ogg_is_re_encoded_to_mono_when_ffmpeg_is_available(): void
    {
        $this->fakeFfmpeg();

        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.ogg', $this->stereoOgg()))->assertCreated();

        Process::assertRan(fn (PendingProcess $p) => in_array('-i', $p->command, true) && in_array('-ac', $p->command, true));
        $this->assertTrue(Message::firstOrFail()->content['voice']);
    }

    public function test_without_ffmpeg_only_mono_ogg_is_sent(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.ogg', $this->stereoOgg()))->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);

        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.ogg', self::OGG.str_repeat("\x00", 64)))->assertCreated();
        $this->assertTrue(Message::firstOrFail()->content['voice']);
    }

    public function test_retry_re_encodes_an_older_failed_voice_note(): void
    {
        $this->fakeFfmpeg();
        Storage::disk('local')->put('whatsapp-media/old/voice.ogg', 'old-stereo-bytes');
        $message = Message::factory()->create([
            'whatsapp_account_id' => $this->account->id, 'conversation_id' => $this->conversation->id, 'contact_id' => $this->conversation->contact_id,
            'direction' => Message::OUT, 'type' => 'audio', 'status' => Message::STATUS_FAILED, 'content' => ['voice' => true], 'body' => null,
        ]);
        $message->attachments()->create(['type' => 'audio', 'mime_type' => 'audio/ogg', 'file_name' => 'voice-note.ogg', 'disk' => 'local', 'path' => 'whatsapp-media/old/voice.ogg', 'wa_media_id' => 'OLD-MEDIA', 'download_status' => 'downloaded']);

        $this->actingAs($this->agent)->postJson("/chat/messages/{$message->id}/retry")->assertOk();

        $attachment = $message->attachments()->firstOrFail();
        $this->assertSame('whatsapp-media/old/voice-mono.ogg', $attachment->path);
        $this->assertSame('MEDIA-VOICE-1', $attachment->wa_media_id); // uploaded again
        Storage::disk('local')->assertExists('whatsapp-media/old/voice-mono.ogg');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['audio']['voice'] ?? false) === true);
    }

    public function test_clear_error_when_ffmpeg_is_missing_or_the_format_is_unknown(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        $this->postVoice($this->webm())->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'ffmpeg'));
        $this->postVoice(UploadedFile::fake()->createWithContent('voice-note.webm', 'just some text'))->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_long_recordings_get_a_lower_bitrate_to_stay_under_512_kb(): void
    {
        // First encode at 24k is 900 KB (a long recording) → re-encode at a lower bitrate.
        Process::fake(function (PendingProcess $process) {
            $cmd = $process->command;
            if (in_array('-i', $cmd, true)) {
                $kbps = $cmd[array_search('-b:a', $cmd, true) + 1];
                file_put_contents(end($cmd), self::OGG.str_repeat("\x00", $kbps === '24k' ? 900 * 1024 : 400 * 1024));
            }

            return Process::result('ok');
        });

        $this->postVoice($this->webm())->assertCreated();

        Process::assertRan(fn (PendingProcess $p) => in_array('-b:a', $p->command, true) && $p->command[array_search('-b:a', $p->command, true) + 1] === '12k');
        $this->assertLessThanOrEqual(512 * 1024, Message::firstOrFail()->attachments()->firstOrFail()->size);
    }

    public function test_voice_note_refused_by_meta_is_resent_once_as_plain_audio(): void
    {
        Process::fake(function (PendingProcess $process) {
            $cmd = $process->command;
            if (in_array('-i', $cmd, true)) {
                file_put_contents(end($cmd), str_ends_with(end($cmd), '.m4a') ? 'aac-bytes' : self::OGG);
            }

            return Process::result('ok');
        });

        $this->postVoice($this->webm())->assertCreated();
        $message = Message::firstOrFail();
        $wamid = $message->wa_message_id;

        // Meta: 131053 "Media upload error" for the voice note.
        $failed = ['errors' => [['code' => 131053, 'title' => 'Media upload error', 'error_data' => ['details' => 'Media file scrutiny for the file failed with mediaEngineStatus: 0']]]];
        $this->postWebhook($this->account, $this->statusPayload($this->account, $wamid, 'failed', $failed))->assertOk();

        $message->refresh();
        $this->assertTrue($message->content['voice_fallback']);
        $this->assertNotSame(Message::STATUS_FAILED, $message->status);
        $attachment = $message->attachments()->firstOrFail();
        $this->assertSame('audio/mp4', $attachment->mime_type);
        $this->assertStringEndsWith('-audio.m4a', $attachment->path);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['type'] === 'audio' && ! array_key_exists('voice', $r['audio']));

        // A second refusal is final (no loop).
        $this->postWebhook($this->account, $this->statusPayload($this->account, $message->wa_message_id, 'failed', $failed, now()->timestamp + 30))->assertOk();
        $this->assertSame(Message::STATUS_FAILED, $message->fresh()->status);
    }

    public function test_chat_page_shows_the_mic_button(): void
    {
        $this->actingAs($this->agent)->get('/chat')->assertOk()->assertSee('Record a voice note')->assertSee('stopAndSendVoice', false)
            // The browser only asks for the mic if the site's Permissions-Policy allows it.
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(self), geolocation=()');
    }
}
