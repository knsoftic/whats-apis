<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Services\ActivityLogger;
use App\Support\LandingContent;
use App\Support\PlatformSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/** Landing page contact form (public) + the admin inbox for those messages. */
class ContactMessageController extends Controller
{
    public function store(Request $request, PlatformSettings $brand): RedirectResponse
    {
        $contact = LandingContent::section('contact');
        abort_unless(($contact['enabled'] ?? false) && ($contact['show_form'] ?? false), 404);

        $back = url('/').'#contact';

        // Honeypot filled → a bot. Pretend it worked, store nothing.
        if (filled($request->input('website'))) {
            return redirect()->to($back)->with('contact_sent', true);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]*$/'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
        ], ['phone.regex' => 'Use digits, spaces, + or - only.']);

        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator, 'contact')->withInput();
        }

        $data = $validator->validated();

        $message = ContactMessage::create([
            'name' => strip_tags($data['name']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => strip_tags($data['message']),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $this->notify($message, $contact['email'] ?: $brand->get('site.support_email'), $brand);

        return redirect()->to($back)->with('contact_sent', true);
    }

    public function index(Request $request): View
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        $messages = ContactMessage::query()
            ->when($filter === 'unread', fn ($q) => $q->unread())
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->query('q')).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('message', 'like', $term));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('contact-messages.index', [
            'messages' => $messages,
            'filter' => $filter,
            'unread' => ContactMessage::unread()->count(),
        ]);
    }

    public function update(Request $request, ContactMessage $message): RedirectResponse
    {
        $message->update(['read_at' => $request->boolean('read') ? now() : null]);

        return back()->with('success', $request->boolean('read') ? 'Marked as read.' : 'Marked as unread.');
    }

    public function markAllRead(): RedirectResponse
    {
        ContactMessage::unread()->update(['read_at' => now()]);

        return back()->with('success', 'All messages marked as read.');
    }

    public function destroy(ContactMessage $message): RedirectResponse
    {
        $message->delete();
        app(ActivityLogger::class)->log('contact_message.deleted', 'Deleted a contact form message from '.$message->email);

        return back()->with('success', 'Message deleted.');
    }

    /** Optional e-mail alert to the support address. Never breaks the form. */
    private function notify(ContactMessage $message, ?string $to, PlatformSettings $brand): void
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $body = "New message from the website contact form\n\n"
                ."Name: {$message->name}\nEmail: {$message->email}\n"
                .($message->phone ? "Phone: {$message->phone}\n" : '')
                ."\n{$message->message}\n\n— ".route('contact-messages.index');

            Mail::raw($body, function ($mail) use ($to, $message, $brand) {
                $mail->to($to)
                    ->replyTo($message->email, $message->name)
                    ->subject('['.$brand->siteName().'] Contact form: '.Str::limit($message->name, 40));
            });
        } catch (Throwable $e) {
            Log::warning('Contact form e-mail could not be sent: '.$e->getMessage());
        }
    }
}
