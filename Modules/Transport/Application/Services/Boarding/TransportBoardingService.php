<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Boarding;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Contracts\ServiceEligibility;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Support\Features;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Transport\Application\Services\TransportEligibilityService;
use Modules\Transport\Application\Services\TransportExceptionRecorder;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Enums\TransportExceptionType;
use Modules\Transport\Domain\Enums\TransportSyncState;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;

/**
 * The boarding engine: one decision pipeline for QR, NFC, manual exceptions
 * and offline sync, from the portal, the admin terminal and the device API.
 *
 *   idempotency replay → medium enabled → trip authority (provider, device,
 *   vehicle) → card → employee and card state → transport eligibility →
 *   [transaction] employee lock → trip lock → boardable, today, duplicate
 *   (trip, leg), capacity → write evidence
 *
 * The server clock dates every online boarding; a device's claim is kept
 * in metadata only. Nothing here trusts a client-supplied provider, route or
 * vehicle for authority. Every accepted boarding is one row that is never
 * edited afterwards (a correction voids it).
 */
final class TransportBoardingService
{
    public const ACCEPTED = 'transport_scan_accepted';

    /** Minutes within which an accepted boarding on another trip is an anomaly, not a second ride. */
    private const SIMULTANEOUS_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly CardCredentialResolver $cards,
        private readonly TransportEligibilityService $eligibility,
        private readonly ServiceEligibility $serviceEligibility,
        private readonly TransportSettings $settings,
        private readonly TransportExceptionRecorder $exceptions,
        private readonly BoardingLock $lock,
        private readonly WriteAuditLogAction $audit,
        private readonly ProviderDirectory $providers,
    ) {}

    public function board(BoardingRequest $request): BoardingDecision
    {
        $providerId = $request->providerId;

        if (! $this->providers->hasService($providerId, 'transport')) {
            return BoardingDecision::refuse('provider_transport_service_disabled');
        }

        $nonce = $request->idempotencyKey;
        if ($nonce !== null && ($replay = $this->replay($providerId, $nonce)) !== null) {
            return $replay;
        }
        $nonce ??= (string) Str::uuid();

        $now = now();
        $boardedAt = $now;

        if ($request->isOffline()) {
            if (! $this->settings->offlineEnabled()) {
                return BoardingDecision::refuse('offline_disabled');
            }
            $boardedAt = $request->offlineRecordedAt ?? $now;
            if ($boardedAt->gt($now->copy()->addMinutes(5)) || $boardedAt->lt($now->copy()->subMinutes($this->settings->offlineGraceMinutes()))) {
                return BoardingDecision::refuse('offline_record_too_old');
            }
        }

        if ($refusal = $this->mediumRefusal($request)) {
            return $refusal;
        }

        if ($request->device !== null && ! $request->device->isActive()) {
            $this->exceptions->record($providerId, TransportExceptionType::UnregisteredDevice, ['device_code' => $request->device->device_code, 'status' => $request->device->status->value], ['device_id' => $request->device->id], 'critical');

            return BoardingDecision::refuse('device_revoked');
        }

        // ── Trip authority ─────────────────────────────────────────────────
        $trip = $request->tripId === null ? null : TransportTrip::query()->with(['route', 'vehicle'])->where('provider_id', $providerId)->whereKey($request->tripId)->first();
        if ($request->tripId !== null && $trip === null) {
            return BoardingDecision::refuse('trip_not_found');
        }
        if ($trip !== null && $request->device?->transport_vehicle_id !== null && $trip->transport_vehicle_id !== $request->device->transport_vehicle_id) {
            $this->exceptions->record($providerId, TransportExceptionType::DeviceTripMismatch, [
                'device_vehicle_id' => $request->device->transport_vehicle_id, 'trip_vehicle_id' => $trip->transport_vehicle_id,
            ], ['trip_id' => $trip->id, 'device_id' => $request->device->id, 'vehicle_id' => $trip->transport_vehicle_id], 'critical');

            return BoardingDecision::refuse('device_trip_mismatch');
        }

        $route = $trip?->route ?? ($request->routeId === null ? null : TransportRoute::query()->where('provider_id', $providerId)->whereKey($request->routeId)->first());
        if ($request->routeId !== null && ($route === null || $route->id !== $request->routeId)) {
            return BoardingDecision::refuse($trip === null ? 'route_not_found' : 'trip_route_mismatch');
        }
        if ($trip === null && $this->settings->requireTripForBoarding()) {
            return BoardingDecision::refuse('trip_required');
        }

        $stop = null;
        if ($request->stopId !== null) {
            $stop = $route === null ? null : TransportRouteStop::query()->where('transport_route_id', $route->id)->whereKey($request->stopId)->first();
            if ($stop === null) {
                return BoardingDecision::refuse('stop_not_on_route');
            }
        }

        // ── Identity ───────────────────────────────────────────────────────
        $card = null;
        if ($request->isManual()) {
            $employee = $request->manualEmployeeId === null ? null : Employee::query()->with('currentAssignment.organization')->find($request->manualEmployeeId);
            if ($employee === null) {
                return BoardingDecision::refuse('employee_not_found');
            }
            if ($employee->status !== EmployeeStatus::Active) {
                return BoardingDecision::refuse('employee_inactive');
            }
            if (trim((string) $request->manualReason) === '') {
                return BoardingDecision::refuse('manual_reason_required');
            }
            $card = $employee->activeIdCard;
        } else {
            $resolved = $this->cards->resolve($request->method, (string) $request->credential);
            $card = $resolved['card'];
            if ($card === null || $card->employee === null) {
                $this->exceptions->record($providerId, TransportExceptionType::UnknownCard, [
                    'reason' => $resolved['reason'], 'method' => $request->method->value, 'credential_hash' => hash('sha256', (string) $request->credential),
                ], ['trip_id' => $trip?->id, 'device_id' => $request->device?->id], 'info', $boardedAt);

                return BoardingDecision::refuse($resolved['reason'] ?? 'card_not_found');
            }
            $employee = $card->employee;

            $attemptedBy = $request->providerUser?->id ?? $request->device?->id;
            $gate = $this->serviceEligibility->checkCard((string) $card->getKey(), 'transport', providerId: $providerId, attemptedBy: $attemptedBy === null ? null : (string) $attemptedBy);
            $state = ['eligible' => $gate->eligible, 'reason_code' => $gate->reasonCode, 'card_status' => $gate->cardStatus];
            if (! $state['eligible']) {
                $transaction = $this->writeRefusal($request, $nonce, $boardedAt, $employee, $card, $trip, $route, $stop, (string) $state['reason_code'], ['card_status' => $state['card_status']]);
                if (in_array($state['reason_code'], ['id_card_revoked', 'id_card_suspended', 'id_card_lost', 'id_card_replaced', 'id_card_expired'], true)) {
                    $this->exceptions->record($providerId, TransportExceptionType::InactiveCardUse, ['reason' => $state['reason_code']], $this->links($transaction), 'warning', $boardedAt);
                }

                return BoardingDecision::refuse((string) $state['reason_code'], $transaction);
            }
        }

        // ── Transport eligibility ──────────────────────────────────────────
        $eligibility = $this->eligibility->check($employee, $card, $providerId, $route, $boardedAt->copy());
        if (! $eligibility['eligible']) {
            $transaction = $this->writeRefusal($request, $nonce, $boardedAt, $employee, $card, $trip, $route, $stop, (string) $eligibility['reason'], [], $eligibility);
            if (in_array($eligibility['reason'], ['route_not_allowed_for_employee', 'route_not_assigned'], true)) {
                $this->exceptions->record($providerId, TransportExceptionType::WrongRoute, ['reason' => $eligibility['reason'], 'assigned_route_id' => $eligibility['snapshot']['assigned_route_id'] ?? null], $this->links($transaction), 'info', $boardedAt);
            }

            return BoardingDecision::refuse((string) $eligibility['reason'], $transaction);
        }

        // ── The atomic decision ────────────────────────────────────────────
        try {
            /** @var array{transaction: TransportTransaction, warnings: list<string>, flags: array<string, mixed>} $outcome */
            $outcome = DB::transaction(function () use ($request, $nonce, $boardedAt, $employee, $card, $trip, $route, $stop, $eligibility): array {
                $this->lock->forEmployee($employee->id);

                $warnings = [];
                $flags = [];
                $refusal = null;
                $locked = $trip === null ? null : TransportTrip::query()->with('vehicle')->whereKey($trip->id)->lockForUpdate()->firstOrFail();
                $leg = $locked === null ? null : TransportTransaction::legFor($boardedAt->toDateString(), $locked->direction);

                if ($locked !== null) {
                    if (! $locked->status->isBoardable()) {
                        $refusal = 'trip_not_boardable';
                    } elseif ($locked->trip_date->toDateString() !== $boardedAt->toDateString()) {
                        $refusal = $request->isOffline() ? 'offline_trip_date_mismatch' : 'trip_not_today';
                    } elseif ($this->boardedOnTrip($locked->id, $employee->id)) {
                        $refusal = 'already_boarded';
                        $flags['duplicate'] = 'trip';
                    } elseif ($leg !== null && $this->settings->duplicatePolicy() === TransportSettings::DUPLICATE_ONE_PER_DIRECTION_PER_DAY && $this->boardedOnLeg($employee->id, $leg)) {
                        $refusal = 'already_boarded_this_leg';
                        $flags['duplicate'] = 'leg';
                    } else {
                        $capacity = $locked->effectiveCapacity();
                        if ($capacity !== null && $locked->boarded_count >= $capacity) {
                            if ($this->settings->capacityBlocks()) {
                                $refusal = 'trip_full';
                            } else {
                                $warnings[] = 'overcapacity';
                            }
                            $flags['overcapacity'] = ['capacity' => $capacity, 'boarded_before' => $locked->boarded_count];
                        }
                    }
                }

                $accepted = $refusal === null;
                if ($accepted && $locked !== null) {
                    TransportTrip::query()->whereKey($locked->id)->increment('boarded_count');
                    if ($this->boardedElsewhereRecently($employee->id, $locked->id, $boardedAt)) {
                        $flags['simultaneous'] = true;
                        $warnings[] = 'simultaneous_boarding';
                    }
                }

                $resultCode = $accepted ? ($eligibility['reason'] ?? self::ACCEPTED) : $refusal;

                $transaction = TransportTransaction::query()->create($this->row($request, $nonce, $boardedAt, $employee, $card, $locked ?? $trip, $route, $stop, $accepted, $resultCode, $eligibility, $leg));

                return ['transaction' => $transaction, 'warnings' => $warnings, 'flags' => $flags];
            });
        } catch (UniqueConstraintViolationException) {
            // Either the same nonce raced in twice (answer with the first) or
            // two scanners hit the (trip, employee) index at once: one boarding.
            if (($replay = $this->replay($providerId, $nonce)) !== null) {
                return $replay;
            }
            $transaction = $this->writeRefusal($request, (string) Str::uuid(), $boardedAt, $employee, $card, $trip, $route, $stop, 'already_boarded', ['race' => true], $eligibility);
            $this->exceptions->record($providerId, TransportExceptionType::DuplicateBoardingAttempt, ['race' => true], $this->links($transaction), 'info', $boardedAt);

            return BoardingDecision::refuse('already_boarded', $transaction);
        }

        $transaction = $outcome['transaction'];
        $this->recordOutcomeExceptions($request, $transaction, $outcome['flags'], $boardedAt);

        $transaction->load(['employee.currentAssignment.organization', 'route', 'trip', 'pass', 'vehicle', 'stop']);
        $accepted = $transaction->status === TransportTransaction::STATUS_ACCEPTED;

        return new BoardingDecision($accepted, (string) $transaction->result_code, $transaction, false, $outcome['warnings']);
    }

    /**
     * Void a boarding: the row stays, marked voided with a reason, and the
     * trip count is corrected. Settled boardings cannot be voided.
     */
    public function void(TransportTransaction $transaction, User $actor, string $reason, ?Request $request = null): TransportTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $reason, $request): TransportTransaction {
            $locked = TransportTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                return $locked;
            }
            if ($locked->transport_settlement_id !== null) {
                throw new \DomainException(__('transport.boarding_settled_cannot_void'));
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason])->save();

            if ($locked->status === TransportTransaction::STATUS_ACCEPTED && $locked->transport_trip_id !== null) {
                TransportTrip::query()->whereKey($locked->transport_trip_id)->where('boarded_count', '>', 0)->decrement('boarded_count');
            }

            $this->audit->execute(AuditEventType::TransportBoardingVoided, $actor, $locked, $locked->employee?->currentAssignment?->organization_id,
                newValues: ['transport_trip_id' => $locked->transport_trip_id, 'employee_id' => $locked->employee_id], reason: $reason, request: $request);

            return $locked;
        });
    }

    /** What the operator reads: the reason for this result code (transport.reasons.*), else the general line. */
    public function message(BoardingDecision $decision): string
    {
        $key = 'transport.reasons.'.$decision->resultCode;

        return trans()->has($key) ? __($key) : __($decision->messageKey());
    }

    private function mediumRefusal(BoardingRequest $request): ?BoardingDecision
    {
        return match ($request->method) {
            TransportVerificationMethod::Qr => $this->settings->qrEnabled() ? null : BoardingDecision::refuse('verification_method_disabled'),
            TransportVerificationMethod::Nfc => $this->settings->nfcEnabled() && Features::enabled('transport_nfc') ? null : BoardingDecision::refuse('verification_method_disabled'),
            TransportVerificationMethod::ManualException => $this->settings->manualExceptionEnabled() ? null : BoardingDecision::refuse('manual_exception_disabled'),
        };
    }

    /**
     * The earlier answer for a repeated key. A key another provider used is
     * refused without revealing that provider's transaction.
     */
    private function replay(string $providerId, string $nonce): ?BoardingDecision
    {
        $existing = TransportTransaction::query()
            ->with(['employee.currentAssignment.organization', 'route', 'trip', 'pass', 'vehicle', 'stop'])
            ->where('scan_nonce', $nonce)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->provider_id !== $providerId) {
            return BoardingDecision::refuse('scan_nonce_conflict');
        }

        return new BoardingDecision($existing->status === TransportTransaction::STATUS_ACCEPTED, 'duplicate_scan_nonce', $existing, true);
    }

    private function boardedOnTrip(string $tripId, string $employeeId): bool
    {
        return TransportTransaction::query()
            ->where('transport_trip_id', $tripId)
            ->where('employee_id', $employeeId)
            ->where('status', TransportTransaction::STATUS_ACCEPTED)
            ->whereNull('voided_at')
            ->exists();
    }

    private function boardedOnLeg(string $employeeId, string $leg): bool
    {
        return TransportTransaction::query()
            ->where('employee_id', $employeeId)
            ->where('boarding_leg', $leg)
            ->where('status', TransportTransaction::STATUS_ACCEPTED)
            ->whereNull('voided_at')
            ->exists();
    }

    private function boardedElsewhereRecently(string $employeeId, string $tripId, Carbon $at): bool
    {
        return TransportTransaction::query()
            ->where('employee_id', $employeeId)
            ->where('transport_trip_id', '<>', $tripId)
            ->where('status', TransportTransaction::STATUS_ACCEPTED)
            ->whereNull('voided_at')
            ->whereBetween('scanned_at', [$at->copy()->subMinutes(self::SIMULTANEOUS_WINDOW_MINUTES), $at->copy()->addMinutes(self::SIMULTANEOUS_WINDOW_MINUTES)])
            ->exists();
    }

    /** A refused scan is evidence too: one rejected row, outside the boarding transaction. @param array<string, mixed> $extra @param array<string, mixed>|null $eligibility */
    private function writeRefusal(BoardingRequest $request, string $nonce, Carbon $boardedAt, Employee $employee, ?IdCard $card, ?TransportTrip $trip, ?TransportRoute $route, ?TransportRouteStop $stop, string $code, array $extra = [], ?array $eligibility = null): TransportTransaction
    {
        $row = $this->row($request, $nonce, $boardedAt, $employee, $card, $trip, $route, $stop, false, $code, $eligibility, TransportTransaction::legFor($boardedAt->toDateString(), $trip?->direction));
        $row['metadata'] = array_filter(($row['metadata'] ?? []) + $extra);

        return TransportTransaction::query()->create($row)->load(['employee.currentAssignment.organization', 'route', 'trip', 'vehicle', 'stop']);
    }

    /** @param array<string, mixed>|null $eligibility @return array<string, mixed> */
    private function row(BoardingRequest $request, string $nonce, Carbon $boardedAt, Employee $employee, ?IdCard $card, ?TransportTrip $trip, ?TransportRoute $route, ?TransportRouteStop $stop, bool $accepted, string $resultCode, ?array $eligibility, ?string $leg): array
    {
        return [
            'provider_id' => $request->providerId,
            'employee_id' => $employee->id,
            'id_card_id' => $card?->id,
            'transport_pass_id' => $eligibility['pass']?->id ?? null,
            'transport_route_id' => $route?->id,
            'transport_trip_id' => $trip?->id,
            'transport_vehicle_id' => $trip?->transport_vehicle_id,
            'transport_device_id' => $request->device?->id,
            'transport_route_stop_id' => $stop?->id,
            'direction' => $trip?->direction,
            'verification_method' => $request->method,
            'boarding_leg' => $leg,
            'eligibility_snapshot' => $eligibility['snapshot'] ?? null,
            'sync_state' => $request->isOffline() ? TransportSyncState::OfflineReconciled : TransportSyncState::Online,
            'offline_recorded_at' => $request->isOffline() ? $boardedAt : null,
            'scanned_at' => $boardedAt,
            'transaction_date' => $boardedAt->toDateString(),
            'status' => $accepted ? TransportTransaction::STATUS_ACCEPTED : TransportTransaction::STATUS_REJECTED,
            'result_code' => $resultCode,
            'rejection_reason' => $accepted ? null : $resultCode,
            'scan_nonce' => $nonce,
            'qr_reference_hash' => $request->credential === null ? null : hash('sha256', $request->credential),
            'scanned_by_provider_user_id' => $request->providerUser?->id,
            'recorded_by_user_id' => $request->user?->id,
            'manual_reason' => $request->isManual() ? $request->manualReason : null,
            'metadata' => array_filter([
                'source' => $request->source,
                // What the scanning device claimed; never used for validity or billing.
                'client_scanned_at' => $request->clientRecordedAt,
                'device_code' => $request->device?->device_code,
                'alternative_route' => ($eligibility['alternative_route'] ?? false) ? true : null,
            ]),
        ];
    }

    /** @param array<string, mixed> $flags */
    private function recordOutcomeExceptions(BoardingRequest $request, TransportTransaction $transaction, array $flags, Carbon $at): void
    {
        $providerId = $request->providerId;
        $links = $this->links($transaction);

        if (isset($flags['duplicate'])) {
            $this->exceptions->record($providerId, TransportExceptionType::DuplicateBoardingAttempt, ['scope' => $flags['duplicate']], $links, 'info', $at);
        }
        if (isset($flags['overcapacity'])) {
            $this->exceptions->record($providerId, TransportExceptionType::Overcapacity, $flags['overcapacity'] + ['accepted' => $transaction->status === TransportTransaction::STATUS_ACCEPTED], $links, 'warning', $at);
        }
        if (isset($flags['simultaneous'])) {
            $this->exceptions->record($providerId, TransportExceptionType::SimultaneousBoarding, ['window_minutes' => self::SIMULTANEOUS_WINDOW_MINUTES], $links, 'critical', $at);
        }

        if ($request->isManual() && $transaction->status === TransportTransaction::STATUS_ACCEPTED) {
            $this->exceptions->record($providerId, TransportExceptionType::ManualBoarding, ['reason' => $request->manualReason], $links, 'warning', $at);
            $this->audit->execute(AuditEventType::TransportManualBoarding, $request->user, $transaction, $transaction->employee?->currentAssignment?->organization_id,
                newValues: ['transport_trip_id' => $transaction->transport_trip_id, 'employee_id' => $transaction->employee_id, 'provider_user_id' => $request->providerUser?->id, 'manual_reason' => $request->manualReason]);

            if ($transaction->transport_trip_id !== null) {
                $manualOnTrip = TransportTransaction::query()->where('transport_trip_id', $transaction->transport_trip_id)
                    ->where('verification_method', TransportVerificationMethod::ManualException->value)->where('status', TransportTransaction::STATUS_ACCEPTED)->count();
                if ($manualOnTrip === $this->settings->manualExceptionsAlertThreshold()) {
                    $this->exceptions->record($providerId, TransportExceptionType::ExcessiveManualExceptions, ['count' => $manualOnTrip], ['trip_id' => $transaction->transport_trip_id, 'vehicle_id' => $transaction->transport_vehicle_id], 'critical', $at);
                }
            }
        }
    }

    /** @return array{trip_id: string|null, transaction_id: string, employee_id: string, vehicle_id: string|null, device_id: string|null} */
    private function links(TransportTransaction $transaction): array
    {
        return [
            'trip_id' => $transaction->transport_trip_id,
            'transaction_id' => $transaction->id,
            'employee_id' => $transaction->employee_id,
            'vehicle_id' => $transaction->transport_vehicle_id,
            'device_id' => $transaction->transport_device_id,
        ];
    }
}
