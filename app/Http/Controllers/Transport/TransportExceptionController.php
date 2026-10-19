<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Transport\TransportExceptionStatus;
use App\Enums\Transport\TransportExceptionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransportExceptionResource;
use App\Models\Provider;
use App\Models\TransportException;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Operational exceptions: facts to review, never accusations. Employee-linked rows follow the viewer's scope. */
class TransportExceptionController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-exceptions.viewAny'), 403);
        $inScope = $scope->employeeIdsInScope($user);

        $exceptions = TransportException::query()->with(['provider', 'trip', 'employee', 'vehicle', 'device', 'reviewedBy'])
            ->when($inScope !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('employee_id')->orWhereIn('employee_id', $inScope)))
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status ?: 'open', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->latest('occurred_at')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Exceptions/Index', [
            'exceptions' => TransportExceptionResource::collection($exceptions)->response()->getData(true),
            'filters' => ['status' => $request->status ?: 'open'] + $request->only('provider_id', 'type', 'severity'),
            'providers' => Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']),
            'types' => TransportExceptionType::values(),
            'can' => ['review' => $user->can('transport-exceptions.review')],
        ]);
    }

    public function review(Request $request, TransportException $exception, WriteAuditLogAction $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-exceptions.review'), 403);
        $data = $request->validate(['status' => ['required', 'in:reviewed,dismissed'], 'review_notes' => ['nullable', 'string', 'max:2000']]);
        $exception->forceFill(['status' => TransportExceptionStatus::from($data['status']), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $data['review_notes'] ?? null])->save();
        $audit->execute(AuditEventType::TransportExceptionReviewed, $request->user(), $exception, null, newValues: ['status' => $data['status'], 'type' => $exception->type->value], reason: $data['review_notes'] ?? null, request: $request);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.exception_reviewed')]);
    }
}
