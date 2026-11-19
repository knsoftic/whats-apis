<?php

namespace App\Http\Controllers;

use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentAccountController extends Controller
{
    public function __invoke(Request $request, CurrentAccount $current): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
        ]);

        $id = isset($data['account_id']) ? (int) $data['account_id'] : null;

        if (! $current->set($id)) {
            // Account doesn't exist or the user has no access — never reveal which.
            abort(403);
        }

        return back()->with('success', $id ? 'Switched WhatsApp number.' : 'Showing all WhatsApp numbers.');
    }
}
