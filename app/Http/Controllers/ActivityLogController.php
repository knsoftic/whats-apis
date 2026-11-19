<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'account:id,name'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('description', 'like', "%{$term}%"))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $actionGroups = [
            'auth' => 'Login / logout',
            'user' => 'Users',
            'account' => 'WhatsApp accounts',
            'message' => 'Messages',
            'contact' => 'Contacts',
            'template' => 'Templates',
            'webhook' => 'Webhooks',
        ];

        return view('activity-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'actionGroups' => $actionGroups,
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
