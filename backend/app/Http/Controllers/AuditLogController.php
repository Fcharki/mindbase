<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public function index(Request $request, Workspace $workspace)
    {
        Gate::authorize('viewAny', [AuditLog::class, $workspace]);

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:255'],
            'entity' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->with('user:id,name,email')
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['entity'])) {
            $query->where('entity_type', $validated['entity']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        // A single `date` pins one day; `from`/`to` bound a range. Combining
        // them is allowed, with `date` narrowing whatever range is set.
        if (! empty($validated['date'])) {
            $query->whereDate('occurred_at', $validated['date']);
        }

        if (! empty($validated['from'])) {
            $query->where('occurred_at', '>=', $validated['from'].' 00:00:00');
        }

        if (! empty($validated['to'])) {
            $query->where('occurred_at', '<=', $validated['to'].' 23:59:59');
        }

        $perPage = $validated['per_page'] ?? 15;

        $auditLogs = $query->paginate($perPage)->withQueryString();

        return AuditLogResource::collection($auditLogs)->additional([
            'filters' => $this->filterOptions($workspace),
        ]);
    }

    public function show(Workspace $workspace, AuditLog $auditLog)
    {
        Gate::authorize('view', $auditLog);

        abort_unless($auditLog->workspace_id === $workspace->id, 404);

        return AuditLogResource::make($auditLog->load('user:id,name,email'));
    }

    // Distinct values across the workspace's history, used to populate the
    // client's filter dropdowns with options that will not return empty pages.
    private function filterOptions(Workspace $workspace): array
    {
        $actions = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        $entities = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->all();

        $users = $workspace->members()
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();

        return [
            'actions' => $actions,
            'entities' => $entities,
            'users' => $users,
        ];
    }
}
