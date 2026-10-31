<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Letter;
use Modules\Court\Domain\Models\LetterCategory;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function letterFormsUser(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Letter forms '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function letterFormsCase(string $caseNumber): CourtCase
{
    $caseType = CaseType::query()->create(['name' => 'Letter forms '.Str::random(4)]);

    return CourtCase::query()->create([
        'case_number' => $caseNumber,
        'title' => 'Abebe Kebede',
        'respondent_name' => 'City Administration',
        'case_type_id' => $caseType->id,
        'filing_date' => now()->toDateString(),
        'status' => 'active',
    ]);
}

/** A real 1x1 PNG, so the `image` rule accepts it without GD. */
function letterFormsPng(string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    return new UploadedFile($path, $name, 'image/png', null, true);
}

test('the compose page carries the templates, the case it is written for and the reference the letter will get', function (): void {
    $user = letterFormsUser(['letters.create']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'category' => 'Hearing', 'body' => '<p>Dear {applicant_name}</p>', 'placeholders' => ['{applicant_name}', '{case_number}']]);
    LetterTemplate::query()->create(['title' => 'Notice', 'body' => '<p>Notice</p>']);
    $case = letterFormsCase('LF-'.Str::upper(Str::random(6)));
    Letter::query()->create(['letter_template_id' => $template->id, 'user_id' => $user->id, 'case_number' => $case->case_number, 'reference_number' => $case->case_number.'/01', 'body' => '<p>First</p>', 'send_to_applicant' => true, 'send_to_respondent' => true]);

    $this->actingAs($user)->get(route('court.letters.compose', ['case_id' => $case->id, 'template_id' => $template->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Letters/Compose')
            ->has('templates', 2)
            ->where('templates.1.id', $template->id)
            ->where('templates.1.category', 'Hearing')
            ->where('templates.1.placeholders', ['{applicant_name}', '{case_number}'])
            ->where('selectedTemplateId', $template->id)
            ->where('caseNumber', $case->case_number)
            ->where('nextReference', $case->case_number.'/02')
            ->where('initial.case_number', $case->case_number)
            ->where('initial.body', '<p>Dear {applicant_name}</p>')
            ->where('initial.send_to_applicant', true)
            ->where('initial.send_to_respondent', true));

    $this->actingAs($user)->get(route('court.letters.compose'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedTemplateId', null)
            ->where('caseNumber', null)
            ->where('nextReference', null)
            ->where('initial.body', ''));
});

test('a letter is saved from the compose page payload and opens as a document', function (): void {
    $user = letterFormsUser(['letters.create', 'letters.view']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Dear {applicant_name}</p>']);
    $case = letterFormsCase('LF-'.Str::upper(Str::random(6)));

    $response = $this->actingAs($user)->post(route('court.letters.store'), [
        'template_id' => $template->id,
        'recipient_name' => '',
        'recipient_title' => 'Mr',
        'recipient_company' => 'Woreda 04',
        'case_number' => $case->case_number,
        'cc' => 'Registrar',
        'send_to_applicant' => '1',
        'send_to_respondent' => '0',
        'subject' => 'Hearing summons',
        'body' => '<p>Dear {applicant_name}, case {case_number}</p>',
    ]);

    $letter = Letter::query()->where('case_number', $case->case_number)->firstOrFail();
    $response->assertRedirect(route('court.letters.show', $letter));
    expect($letter->reference_number)->toBe($case->case_number.'/01')
        ->and($letter->subject)->toBe('Hearing summons')
        ->and($letter->send_to_applicant)->toBeTrue()
        ->and($letter->send_to_respondent)->toBeFalse()
        ->and($letter->recipient_name)->toBe('Abebe Kebede')
        ->and($letter->body)->toContain('Abebe Kebede')
        ->and($letter->body)->toContain($case->case_number);
});

test('a refused save brings the compose page back with the errors and the submitted values', function (): void {
    $user = letterFormsUser(['letters.create']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Body</p>']);

    $this->actingAs($user)->from(route('court.letters.compose'))->post(route('court.letters.store'), [
        'template_id' => $template->id,
        'recipient_name' => 'Draft recipient',
        'subject' => 'Draft subject',
        'body' => '<p>Draft body</p>',
        'send_to_applicant' => '0',
        'send_to_respondent' => '0',
    ])
        ->assertRedirect(route('court.letters.compose'))
        ->assertSessionHasErrors('send_to_applicant');

    $this->actingAs($user)->get(route('court.letters.compose'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Letters/Compose')
            ->where('initial.recipient_name', 'Draft recipient')
            ->where('initial.subject', 'Draft subject')
            ->where('initial.body', '<p>Draft body</p>')
            ->where('initial.send_to_applicant', false)
            ->where('initial.send_to_respondent', false)
            ->has('errors.send_to_applicant'));
});

test('the edit page carries the letter and the references of its template', function (): void {
    $user = letterFormsUser(['letters.update']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'subject_prefix' => 'LTR', 'reference_sequence' => 3, 'body' => '<p>Body</p>']);
    $letter = Letter::query()->create([
        'letter_template_id' => $template->id, 'user_id' => $user->id, 'recipient_name' => 'Abebe Kebede', 'recipient_title' => 'Mr',
        'subject' => 'Summons for hearing', 'reference_number' => 'LF-1/01', 'case_number' => 'LF-1', 'body' => '<p>Please attend.</p>', 'cc' => 'Registrar',
        'send_to_applicant' => true, 'send_to_respondent' => false, 'approval_status' => 'pending',
    ]);

    $this->actingAs($user)->get(route('court.letters.edit', $letter))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Letters/Edit')
            ->where('letter.id', $letter->id)
            ->where('letter.reference_number', 'LF-1/01')
            ->where('letter.approval_status', 'pending')
            ->where('letter.recipient_name', 'Abebe Kebede')
            ->where('letter.case_number', 'LF-1')
            ->where('letter.body', '<p>Please attend.</p>')
            ->where('letter.send_to_applicant', true)
            ->where('letter.send_to_respondent', false)
            ->where('template.title', 'Summons')
            ->where('template.subject_prefix', 'LTR')
            ->where('template.next_reference', 'LTR/0004'));

    $this->actingAs($user)->patch(route('court.letters.update', $letter), [
        'recipient_name' => 'Abebe Kebede', 'subject' => 'Updated subject', 'body' => '<p>Updated.</p>', 'send_to_applicant' => '1', 'send_to_respondent' => '1',
    ])->assertRedirect(route('court.letters.show', $letter));
    expect($letter->fresh()->subject)->toBe('Updated subject');
});

test('the templates list carries each template with its tags and the permissions', function (): void {
    $user = letterFormsUser(['letters.templet.view', 'letters.templet.update', 'letters.create']);
    $first = LetterTemplate::query()->create(['title' => 'Appeal notice', 'category' => 'Appeal', 'body' => '<p>Body</p>', 'placeholders' => ['{case_number}']]);
    LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Body</p>']);

    $this->actingAs($user)->get(route('court.letter-templates.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/LetterTemplates/Index')
            ->where('templates.total', 2)
            ->where('templates.data.0.id', $first->id)
            ->where('templates.data.0.category', 'Appeal')
            ->where('templates.data.0.placeholders', ['{case_number}'])
            ->where('templates.data.1.category', null)
            ->where('templates.data.1.placeholders', [])
            ->where('can.update', true)
            ->where('can.compose', true)
            ->where('can.categories', true)
            ->where('can.create', false)
            ->where('can.delete', false));
});

test('the template form pages carry the categories and, when editing, the template with its images', function (): void {
    $user = letterFormsUser(['letters.templet.create', 'letters.templet.update']);
    LetterCategory::query()->create(['name' => 'Summons']);
    LetterCategory::query()->create(['name' => 'Appeal']);
    $template = LetterTemplate::query()->create([
        'title' => 'Summons', 'category' => 'Summons', 'body' => '<p>Dear {applicant_name}</p>',
        'placeholders' => ['{applicant_name}', '{case_number}'], 'header_image_path' => 'letter-templates/headers/header.png',
    ]);

    $this->actingAs($user)->get(route('court.letter-templates.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/LetterTemplates/Form')
            ->where('template', null)
            ->where('categories', ['Appeal', 'Summons']));

    $this->actingAs($user)->get(route('court.letter-templates.edit', $template))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/LetterTemplates/Form')
            ->where('template.id', $template->id)
            ->where('template.title', 'Summons')
            ->where('template.placeholders', '{applicant_name}, {case_number}')
            ->where('template.body', '<p>Dear {applicant_name}</p>')
            ->where('template.header_image_name', 'header.png')
            ->where('template.header_image_url', fn (string $url) => str_ends_with($url, '/storage/letter-templates/headers/header.png'))
            ->where('template.footer_image_url', null)
            ->where('categories', ['Appeal', 'Summons']));
});

test('a template is saved and updated from the multipart form payload, images included', function (): void {
    Storage::fake('court_public');
    $user = letterFormsUser(['letters.templet.create', 'letters.templet.update']);

    $this->actingAs($user)->post(route('court.letter-templates.store'), [
        'title' => 'Summons',
        'category' => 'Hearing',
        'placeholders' => "{applicant_name}, {case_number}\n{case_number}",
        'body' => '<p>Dear {applicant_name}</p>',
        'header_image' => letterFormsPng('header.png'),
        'remove_header_image' => '0',
        'remove_footer_image' => '0',
    ])->assertRedirect(route('court.letter-templates.index'));

    $template = LetterTemplate::query()->where('title', 'Summons')->firstOrFail();
    expect($template->placeholders)->toBe(['{applicant_name}', '{case_number}'])
        ->and($template->category)->toBe('Hearing')
        ->and($template->header_image_path)->toStartWith('letter-templates/headers/');
    Storage::disk('court_public')->assertExists($template->header_image_path);

    // The edit form posts PATCH as multipart with _method, the way Laravel reads it.
    $this->actingAs($user)->post(route('court.letter-templates.update', $template), [
        '_method' => 'PATCH',
        'title' => 'Summons (revised)',
        'category' => 'Hearing',
        'placeholders' => '{applicant_name}',
        'body' => '<p>Dear {applicant_name}</p>',
        'remove_header_image' => '1',
        'remove_footer_image' => '0',
        'footer_image' => letterFormsPng('footer.png'),
    ])->assertRedirect(route('court.letter-templates.index'));

    $template->refresh();
    expect($template->title)->toBe('Summons (revised)')
        ->and($template->header_image_path)->toBeNull()
        ->and($template->footer_image_path)->toStartWith('letter-templates/footers/');
    Storage::disk('court_public')->assertExists($template->footer_image_path);
});

test('the categories list carries each category with the permissions', function (): void {
    $user = letterFormsUser(['letters.templet.view', 'letters.templet.create', 'letters.templet.delete']);
    LetterCategory::query()->create(['name' => 'Summons', 'description' => 'Hearing summons']);
    LetterCategory::query()->create(['name' => 'Appeal']);

    $this->actingAs($user)->get(route('court.letter-categories.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/LetterCategories/Index')
            ->where('categories.total', 2)
            ->where('categories.data.0.name', 'Appeal')
            ->where('categories.data.0.description', null)
            ->where('categories.data.1.name', 'Summons')
            ->where('categories.data.1.description', 'Hearing summons')
            ->where('can.create', true)
            ->where('can.delete', true)
            ->where('can.update', false));
});

test('the category form pages carry the category and a category is saved from the payload', function (): void {
    $user = letterFormsUser(['letters.templet.create', 'letters.templet.update']);

    $this->actingAs($user)->get(route('court.letter-categories.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/LetterCategories/Form')->where('category', null));

    $this->actingAs($user)->post(route('court.letter-categories.store'), ['name' => 'Summons', 'description' => 'Hearing summons'])
        ->assertRedirect(route('court.letter-categories.index'));
    $category = LetterCategory::query()->where('name', 'Summons')->firstOrFail();

    $this->actingAs($user)->get(route('court.letter-categories.edit', $category))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/LetterCategories/Form')
            ->where('category.id', $category->id)
            ->where('category.name', 'Summons')
            ->where('category.description', 'Hearing summons'));

    $this->actingAs($user)->patch(route('court.letter-categories.update', $category), ['name' => 'Summons', 'description' => ''])
        ->assertRedirect(route('court.letter-categories.index'));
    expect($category->fresh()->description)->toBeEmpty();
});
