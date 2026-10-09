<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TransferDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('view', TransferSetting::class);

        $user = Auth::user();

        $activeAnnouncements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published->value)
            ->count();

        $pendingApplications = TransferApplication::query()
            ->whereNotIn('status', array_map(
                fn (TransferApplicationStatus $s) => $s->value,
                array_filter(TransferApplicationStatus::cases(), fn ($s) => $s->isFinal()),
            ))
            ->count();

        $releasePending = TransferApplication::query()
            ->where('status', TransferApplicationStatus::ReleasePending->value)
            ->count();

        $receivingPending = TransferApplication::query()
            ->where('status', TransferApplicationStatus::ReceivingPending->value)
            ->count();

        $finalPending = TransferApplication::query()
            ->where('status', TransferApplicationStatus::FinalApprovalPending->value)
            ->count();

        $recentTransfers = TransferApplication::query()
            ->where('status', TransferApplicationStatus::Transferred->value)
            ->with(['employee', 'releasingOrganization', 'receivingOrganization'])
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
