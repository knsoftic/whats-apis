<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $users = User::query()
            ->with(['roles', 'whatsappAccounts:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Permissions::roles(),
            'accounts' => WhatsAppAccount::orderBy('name')->get(['id', 'name', 'display_phone_number']),
            'selectedAccounts' => [],
            'currentRole' => Permissions::ROLE_AGENT,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$data['role']]);
            $user->whatsappAccounts()->sync($data['account_ids'] ?? []);

            return $user;
        });

        $this->logger->log('user.created', "Created user {$user->email} ({$data['role']})", $user, [
            'role' => $data['role'],
            'account_ids' => $data['account_ids'] ?? [],
        ]);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'roles' => Permissions::roles(),
            'accounts' => WhatsAppAccount::orderBy('name')->get(['id', 'name', 'display_phone_number']),
            'selectedAccounts' => $user->whatsappAccounts()->pluck('whatsapp_accounts.id')->all(),
            'currentRole' => $user->roleName(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $isSelf = $request->user()->is($user);

        if ($isSelf && (! $data['is_active'] || $data['role'] !== $user->roleName())) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role or deactivate yourself.',
            ]);
        }

        $this->guardLastSuperAdmin($user, $data['role'], $data['is_active']);

        DB::transaction(function () use ($user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles([$data['role']]);
            $user->whatsappAccounts()->sync($data['account_ids'] ?? []);
        });

        $this->logger->log('user.updated', "Updated user {$user->email}", $user, [
            'role' => $data['role'],
            'is_active' => $data['is_active'],
            'password_changed' => ! empty($data['password']),
            'account_ids' => $data['account_ids'] ?? [],
        ]);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $this->guardLastSuperAdmin($user, null, false);

        $email = $user->email;
        $user->delete();

        $this->logger->log('user.deleted', "Deleted user {$email}", properties: ['email' => $email]);

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }

    /**
     * There must always be at least one active Super Admin.
     */
    private function guardLastSuperAdmin(User $user, ?string $newRole, bool $stillActive): void
    {
        if (! $user->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            return;
        }

        $remainsSuperAdmin = $newRole === Permissions::ROLE_SUPER_ADMIN && $stillActive;

        if ($remainsSuperAdmin) {
            return;
        }

        $others = User::role(Permissions::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([
                'role' => 'This is the last active Super Admin and cannot be removed, demoted or deactivated.',
            ]);
        }
    }
}
