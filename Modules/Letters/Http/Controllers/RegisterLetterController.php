<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Actions\AddAttachment;
use Modules\Letters\Application\Actions\RegisterIncomingLetter;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Domain\Enums\AttachmentKind;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Http\Requests\RegisterIncomingRequest;

/** Registering a letter that arrived from outside the system (a full page, not a dialog). */
final class RegisterLetterController extends Controller
{
    public function __construct(
        private readonly LetterLookups $lookups,
        private readonly LetterStandards $standards,
    ) {}

    public function create(Request $request): Response
    {
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, 'letters.register');
        abort_if($organizations === [], 403);
        $selected = (string) $request->string('organization_id', count($organizations) === 1 ? $organizations[0]['id'] : '');
        $selected = collect($organizations)->contains('id', $selected) ? $selected : '';

        return Inertia::render('Letters/Register', [
            'organizations' => $organizations,
            'selected_organization_id' => $selected,
            'units' => $selected !== '' ? $this->lookups->units($selected) : [],
            'all_organizations' => $this->lookups->allOrganizations(),
            'priorities' => LetterPriority::values(),
            'file_classes' => $selected !== '' ? $this->standards->fileClassesFor($selected) : [],
            'standard' => $selected !== '' ? $this->standards->for($selected) : null,
        ]);
    }

    public function store(RegisterIncomingRequest $request, RegisterIncomingLetter $register, AddAttachment $attach): RedirectResponse
    {
        $data = $request->validated();
        $letter = $register->execute($data, $request->user());
        foreach ($request->file('files', []) as $file) {
            $attach->execute($letter, $file, AttachmentKind::Scan, $request->user());
        }

        return redirect()->route('letters.show', $letter)->with('success', __('letters.flash.registered', ['number' => $letter->referenceNumber()]));
    }
}
