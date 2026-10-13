<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TransferDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('view', TransferSetting::class);

        $user = Auth::user();
        $scope = app(OrganizationScopeService::class);
        $restricted = ! $scope->isUnrestricted($user);
        $allowed = $restricted ? $scope->allowedOrganizationIds($user) : [];

        // Same rule as the applications list: a scoped user sees transfers
        // leaving or entering their organizations, and announcements of them.
        $applications = fn () => TransferApplication::query()->when($restricted, fn ($query) => $query
            ->where(fn ($scoped) => $scoped
                ->whereIn('releasing_organization_id', $allowed)
                ->orWhereIn('receiving_organization_id', $allowed)));

        $activeAnnouncements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published->value)
            ->when($restricted, fn ($query) => $query->whereIn('organization_id', $allowed))
            ->count();

        $finalStatuses = array_map(
            fn (TransferApplicationStatus $s) => $s->value,
            array_filter(TransferApplicationStatus::cases(), fn ($s) => $s->isFinal()),
        );

        // One grouped query instead of four separate counts.
        $byStatus = $applications()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        $pendingApplications = (int) $byStatus->except($finalStatuses)->sum();
        $releasePending = (int) ($byStatus[TransferApplicationStatus::ReleasePending->value] ?? 0);
        $receivingPending = (int) ($byStatus[TransferApplicationStatus::ReceivingPending->value] ?? 0);
        $finalPending = (int) ($byStatus[TransferApplicationStatus::FinalApprovalPending->value] ?? 0);

        $recentTransfers = $applications()
            ->where('status', TransferApplicationStatus::Transferred->value)
            ->with(['employee:id,employee_number,full_name,name_en', 'releasingOrganization', 'receivingOrganization'])
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Transfers/Dashboard', [
            'stats' => [
                'active_announcements' => $activeAnnouncements,
                'pending_applications' => $pendingApplications,
                'release_pending' => $releasePending,
                'receiving_pending' => $receivingPending,
                'final_pending' => $finalPending,
            ],
            'recent_transfers' => $recentTransfers,
            'can' => [
                'manage_settings' => $user?->can('transfers.settings.manage') ?? false,
                'create_announcement' => $user?->can('transfers.announcements.create') ?? false,
                'view_applications' => $user?->can('transfers.applications.view') ?? false,
                'approve_release' => $user?->can('transfers.release.approve') ?? false,
                'approve_receiving' => $user?->can('transfers.receiving.approve') ?? false,
                'approve_final' => $user?->can('transfers.final.approve') ?? false,
            ],
        ]);
    }
}
