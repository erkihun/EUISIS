<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index()
    {
        $teams = Team::with(['parent', 'children', 'users', 'leader'])->orderBy('name')->get();

        // The register (React, Court/Teams/Index).
        return Inertia::render('Court/Teams/Index', [
            'teams' => $teams->map(fn (Team $team): array => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'parent_name' => $team->parent?->name,
                'description' => $team->description,
                'members_count' => $team->users->count(),
                'leader_name' => $team->leader?->name,
                'leader_email' => $team->leader?->email,
            ])->values()->all(),
        ]);
    }

    public function create()
    {
        // The create form (React, Court/Teams/Create): possible parents and the people free to lead.
        return Inertia::render('Court/Teams/Create', [
            'teams' => $this->teamOptions(Team::orderBy('name')->get()),
            'users' => $this->userOptions($this->availableUsers()),
        ]);
    }

    public function show(Team $team)
    {
        $team->load(['parent', 'children', 'users', 'leader']);

        // The team page (React, Court/Teams/Show) with its members.
        return Inertia::render('Court/Teams/Show', [
            'team' => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'parent_name' => $team->parent?->name,
                'description' => $team->description,
                'leader_name' => $team->leader?->name,
                'members' => $this->userOptions($team->users),
            ],
        ]);
    }

    public function edit(Team $team)
    {
        $team->load('users');

        // The edit form (React, Court/Teams/Edit): details on one side, membership on the other.
        // The people offered are those in no other team, so the current members stay listed.
        return Inertia::render('Court/Teams/Edit', [
            'team' => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'parent_id' => $team->parent_id !== null ? (int) $team->parent_id : null,
                'description' => $team->description,
                'team_leader_id' => $team->team_leader_id !== null ? (int) $team->team_leader_id : null,
                'user_ids' => $team->users->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ],
            // A team cannot report to itself or to one of its own sub-teams.
            'teams' => $this->teamOptions(Team::whereNotIn('id', $this->descendantIds($team)->push((int) $team->id)->all())->orderBy('name')->get()),
            'users' => $this->userOptions($this->availableUsers($team)),
        ]);
    }

    /** Teams as select options. */
    private function teamOptions($teams): array
    {
        return collect($teams)
            ->map(fn (Team $team): array => ['id' => (int) $team->id, 'name' => (string) $team->name])
            ->values()
            ->all();
    }

    /** People as select options and member rows. */
    private function userOptions($users): array
    {
        return collect($users)
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email])
            ->values()
            ->all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:court_teams,id'],
            'description' => ['nullable', 'string'],
            'team_leader_id' => ['nullable', 'exists:court_staff,id'],
        ]);

        $team = Team::create($data);

        if (!empty($data['team_leader_id'])) {
            $this->ensureSingleTeamForUser($data['team_leader_id'], $team->id);
            $team->users()->syncWithoutDetaching([$data['team_leader_id']]);
        }

        return redirect()->route('court.teams.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.team')],
        ]);
    }

    public function update(Request $request, Team $team)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:court_teams,id'],
            'description' => ['nullable', 'string'],
            'team_leader_id' => ['nullable', 'exists:court_staff,id'],
        ]);

        // A team cannot report to itself or to one of its sub-teams: the
        // hierarchy would loop and the team would drop out of the tree.
        if (! empty($data['parent_id']) && $this->descendantIds($team)->push((int) $team->id)->contains((int) $data['parent_id'])) {
            throw ValidationException::withMessages(['parent_id' => __('court::teams.errors.parent_cycle')]);
        }

        $team->update($data);

        if (!empty($data['team_leader_id'])) {
            $this->ensureSingleTeamForUser($data['team_leader_id'], $team->id);
            $team->users()->syncWithoutDetaching([$data['team_leader_id']]);
        }

        return redirect()->route('court.teams.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.team')],
        ]);
    }

    public function destroy(Team $team)
    {
        if ($team->users()->exists()) {
            return redirect()->route('court.teams.index')->with('error', 'court::messages.error.team_has_members');
        }

        $team->delete();

        return redirect()->route('court.teams.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.team')],
        ]);
    }

    public function updateUsers(Request $request, Team $team)
    {
        $userIds = collect($request->input('users', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $leaderAdded = false;
        if ($team->team_leader_id && !in_array($team->team_leader_id, $userIds, true)) {
            $userIds[] = $team->team_leader_id;
            $leaderAdded = true;
        }

        DB::transaction(function () use ($team, $userIds) {
            if (!empty($userIds)) {
                DB::table('court_team_user')
                    ->whereIn('user_id', $userIds)
                    ->where('team_id', '!=', $team->id)
                    ->delete();
            }
            $team->users()->sync($userIds);
        });

        $message = $leaderAdded
            ? [
                'key' => 'court::messages.success.team_membership_updated_leader',
                'replace' => ['leader' => $team->leader?->name ?? __('court::messages.resources.user')],
            ]
            : 'court::messages.success.team_membership_updated';

        return redirect()->route('court.teams.edit', $team)->with('success', $message);
    }

    /**
     * Every team below this one, however deep (breadth-first over parent_id;
     * the visited set stops at a loop already stored).
     *
     * @return Collection<int, int>
     */
    private function descendantIds(Team $team): Collection
    {
        $seen = [(int) $team->id => true];
        $found = [];
        $frontier = [(int) $team->id];

        while ($frontier !== []) {
            $children = Team::whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => isset($seen[$id]))
                ->values()
                ->all();
            foreach ($children as $id) {
                $seen[$id] = true;
                $found[] = $id;
            }
            $frontier = $children;
        }

        return collect($found);
    }

    private function ensureSingleTeamForUser(int $userId, ?int $currentTeamId = null): void
    {
        $query = DB::table('court_team_user')->where('user_id', $userId);
        if ($currentTeamId !== null) {
            $query->where('team_id', '!=', $currentTeamId);
        }
        $query->delete();
    }

    private function availableUsers(?Team $team = null)
    {
        $assigned = DB::table('court_team_user')
            ->when($team, fn($q) => $q->where('team_id', '!=', $team->id))
            ->pluck('user_id');

        return User::whereNotIn('id', $assigned)
            ->orderBy('name')
            ->get();
    }
}
