<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

readonly class UpdateCafeteriaSettingsAction
{
    public function __construct(
        private CafeteriaSettingsService $settingsService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $settings, User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($settings, $actor, $request): void {
            $old = $this->settingsService->all();

            $this->settingsService->setMany($settings);

            $this->writeAuditLogAction->execute(
                AuditEventType::CafeteriaSettingsUpdated,
                $actor,
                null,
                null,
                oldValues: array_intersect_key($old, $settings),
                newValues: $settings,
                request: $request,
            );
        });
    }
}
