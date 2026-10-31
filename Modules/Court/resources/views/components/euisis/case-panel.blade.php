{{-- EUISIS panel on the admin case page (Modules\Court\Http\View\Components\Euisis\CasePanel, docs/euisis-integration.md) --}}
@php
    $canLink = function_exists('userHasPermission')
        ? \Modules\Court\Application\Support\CourtPermissions::check('cases.edit')
        : (auth()->user()?->hasPermission('cases.edit') ?? false);
    $notice = session('euisis_status');
    $bag = ($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag('euisis');
@endphp
<div class="rounded-xl border border-teal-100 bg-teal-50/40 p-4 space-y-4" id="euisis-panel">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-teal-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            </span>
            <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">{{ __('court::euisis.panel.title') }}</div>
        </div>
        @if($referral)
            <span class="inline-flex items-center rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-800">{{ __('court::euisis.panel.referred_badge') }}</span>
        @endif
    </div>

    @if($notice)
        <p class="rounded-lg bg-white/80 border border-teal-200 px-3 py-2 text-sm text-teal-900">{{ $notice }}</p>
    @endif
    @if($bag->any())
        <ul class="rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800 space-y-0.5">
            @foreach($bag->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    @endif

    @if($referral)
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('court::euisis.panel.grievance_reference') }}</dt><dd class="font-mono text-gray-900">{{ $referral->grievance_reference ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('court::euisis.panel.tribunal_case_number') }}</dt><dd class="font-mono text-gray-900">{{ $referral->tribunal_case_number ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('court::euisis.panel.complainant') }}</dt><dd class="text-gray-900">{{ $referral->complainant_name ?: '—' }}
                @if($referral->complainant_phone || $referral->complainant_email)
                    <span class="block text-xs text-gray-500">{{ collect([$referral->complainant_phone, $referral->complainant_email])->filter()->implode(' · ') }}</span>
                @endif
            </dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('court::euisis.panel.referring_officer') }}</dt><dd class="text-gray-900">{{ $referral->referring_officer ?: '—' }}
                <span class="block text-xs text-gray-500">{{ __('court::euisis.panel.received_at', ['date' => $referral->created_at?->format('Y-m-d H:i')]) }}</span>
            </dd></div>
        </dl>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Respondent organization --}}
        <div class="rounded-lg bg-white/80 border border-gray-200 p-3 space-y-2">
            <div class="text-xs font-semibold text-gray-600">{{ __('court::euisis.panel.respondent_organization') }}</div>
            @if($organization)
                <p class="font-semibold text-gray-900">{{ $organization->displayName() }}
                    @if($organization->code)<span class="font-mono text-xs text-gray-500">{{ $organization->code }}</span>@endif
                </p>
                @unless($organization->isActive())
                    <p class="text-xs font-semibold text-amber-700">{{ __('court::euisis.panel.organization_inactive', ['status' => $organization->status]) }}</p>
                @endunless
            @else
                <p class="text-sm text-gray-500">{{ __('court::euisis.panel.not_linked') }}</p>
            @endif

            @if($canLink && $hasOrganizations)
                <form method="POST" action="{{ route('court.cases.euisis.organization', $caseId) }}" class="flex flex-wrap gap-2 items-end">
                    @csrf
                    @method('PATCH')
                    <select name="organization_uuid" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm" aria-label="{{ __('court::euisis.panel.respondent_organization') }}">
                        <option value="">{{ __('court::euisis.panel.no_organization') }}</option>
                        @foreach($organizations() as $option)
                            <option value="{{ $option->uuid }}" @selected($organization?->uuid === $option->uuid)>{{ $option->displayName() }}{{ $option->isActive() ? '' : ' ('.$option->status.')' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-lg bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800">{{ __('court::euisis.panel.link') }}</button>
                </form>
            @elseif($canLink)
                <p class="text-xs text-gray-500">{{ __('court::euisis.panel.no_cache') }}</p>
            @endif
        </div>

        {{-- Applicant / complainant as an EUISIS employee --}}
        <div class="rounded-lg bg-white/80 border border-gray-200 p-3 space-y-2">
            <div class="text-xs font-semibold text-gray-600">{{ $case?->applicant_id ? __('court::euisis.panel.applicant_employee') : __('court::euisis.panel.complainant_employee') }}</div>
            @if($employee)
                <p class="font-semibold text-gray-900">{{ $employee->displayName() }} <span class="font-mono text-xs text-gray-500">{{ $employee->employee_number }}</span></p>
                <p class="text-xs text-gray-600">
                    {{ $position?->displayTitle() ?? __('court::euisis.panel.position_unknown') }}
                    · {{ $employeeOrganization?->displayName() ?? '—' }}
                </p>
                @if($employee->status && $employee->status !== 'active')
                    <p class="text-xs font-semibold text-amber-700">{{ __('court::euisis.panel.employee_status', ['status' => $employee->status]) }}</p>
                @endif
            @else
                <p class="text-sm text-gray-500">{{ __('court::euisis.panel.not_linked') }}</p>
            @endif

            @if($canLink && $enabled && ($case?->applicant_id || $referral))
                <form method="POST" action="{{ route('court.cases.euisis.employee', $caseId) }}" class="flex flex-wrap gap-2 items-end">
                    @csrf
                    <input name="employee_number" value="{{ old('employee_number') }}" maxlength="60" required
                        placeholder="{{ __('court::euisis.panel.employee_number') }}" aria-label="{{ __('court::euisis.panel.employee_number') }}"
                        class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <button type="submit" class="rounded-lg bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800">{{ __('court::euisis.panel.find_and_link') }}</button>
                </form>
            @endif
        </div>
    </div>

    @if($enabled)
        <p class="text-xs text-gray-500">
            {{ __('court::euisis.panel.last_sync', ['time' => $lastRun ? \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans() : __('court::euisis.panel.never')]) }}
            @if($lastError)<span class="text-rose-700">· {{ __('court::euisis.panel.last_error', ['error' => $lastError]) }}</span>@endif
        </p>
    @endif
</div>
