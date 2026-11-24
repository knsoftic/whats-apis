<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Block / unblock a customer on WhatsApp (Meta Block Users API). */
class ContactBlockController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function store(Request $request, Contact $contact): RedirectResponse|JsonResponse
    {
        return $this->change($request, $contact, true);
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse|JsonResponse
    {
        return $this->change($request, $contact, false);
    }

    private function change(Request $request, Contact $contact, bool $block): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $contact);

        try {
            $result = WhatsAppCloudApiService::for($contact->account)->blockUsers([$contact->wa_id], $block);
        } catch (WhatsAppApiException $e) {
            return $this->reply($request, false, 'Meta: '.$e->getMessage(), $contact);
        }

        if ($result['failed'] !== []) {
            $why = $result['failed'][0]['error'];
            $hint = $block ? ' (Meta only lets you block people who messaged this number in the last 24 hours.)' : '';

            return $this->reply($request, false, 'Meta: '.$why.$hint, $contact);
        }

        $contact->forceFill(['blocked_at' => $block ? now() : null])->save();
        $this->logger->log($block ? 'contact.blocked' : 'contact.unblocked', ($block ? 'Blocked ' : 'Unblocked ').$contact->phone.' on WhatsApp', $contact, accountId: $contact->whatsapp_account_id);

        return $this->reply($request, true, $block
            ? "{$contact->displayName()} is blocked — they can't message this number any more."
            : "{$contact->displayName()} is unblocked.", $contact);
    }

    private function reply(Request $request, bool $ok, string $message, Contact $contact): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'blocked' => $contact->blocked_at !== null], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
