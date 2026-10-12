<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Http\Middleware\RequestCorrelationId;
use App\Jobs\ExportPublicFeedbackReport;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackReportExport;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §76–78: what the public flow does when something behind it is
 * down. Storage failing mid-submission rolls everything back and leaves no
 * file behind; a notification provider failing never fails the submission;
 * a missing queue worker leaves an export visibly queued; nothing a citizen
 * wrote, and no QR secret, is ever written to the log; every public
 * response carries the correlation id.
 */

const RES_NARRATIVE = 'NARRATIVE-SENTINEL the officer kept my file for weeks';
const RES_CONTACT = 'sentinel-contact@example.invalid';

beforeEach(function (): void {
    app()->setLocale('en');
    foreach (['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.export', 'service_feedback.manage_routing', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Service Quality Team', 'web')->syncPermissions(['service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target']);

    $this->a = PublicFeedbackScenario::employeeTarget('RES');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
    $this->handler = User::factory()->create(['name' => 'Quality Officer']);
    $this->handler->assignRole('Service Quality Team');
    UserOrganizationScope::query()->create(['user_id' => $this->handler->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);
    PublicFeedbackRoutingRule::query()->create([
        'organization_id' => $this->a->organization->id, 'handler_type' => 'user', 'handler_id' => (string) $this->handler->id,
        'priority' => 10, 'is_active' => true, 'created_by' => $this->superAdmin->id,
    ]);
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');
});

it('rolls the whole submission back when an attachment cannot be stored and tells the citizen, leaving no file and no receipt', function (): void {
    $disk = Storage::fake('local');
    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('putFileAs')->once()->passthru();
    $failing->shouldReceive('putFileAs')->andThrow(new RuntimeException('No space left on device'));
    Storage::set('local', $failing);
    $captured = [];
    Log::listen(function (MessageLogged $event) use (&$captured): void {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    $response = $this->from('/service-feedback/'.$this->a->token->token.'/complaint')
        ->post('/service-feedback/'.$this->a->token->token.'/complaint', [
            'message' => RES_NARRATIVE,
            'attachments' => [UploadedFile::fake()->image('first.jpg', 40, 40), UploadedFile::fake()->image('second.jpg', 40, 40)],
        ]);

    $response->assertRedirect('/service-feedback/'.$this->a->token->token.'/complaint')
        ->assertSessionHasErrors('message')
        ->assertSessionMissing('feedback_receipt');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0)
        ->and(DB::table('employee_service_feedback_attachments')->count())->toBe(0)
        ->and(DB::table('public_feedback_status_history')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event_type', 'like', '%submitted%')->count())->toBe(0)
        ->and($disk->allFiles('service-feedback'))->toBe([])
        ->and(collect($captured)->filter(fn (string $line): bool => str_contains($line, RES_NARRATIVE) || str_contains($line, $this->a->token->token))->all())->toBe([]);
});

it('keeps the submission and the receipt when the notification provider throws, and logs no narrative', function (): void {
    $this->app->instance(NotificationDispatcher::class, Mockery::mock(NotificationDispatcher::class, function ($mock): void {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('smtp unreachable'));
        $mock->shouldReceive('sendNow')->andThrow(new RuntimeException('smtp unreachable'));
    }));
    $captured = [];
    Log::listen(function (MessageLogged $event) use (&$captured): void {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => RES_NARRATIVE, 'client_contact' => RES_CONTACT])
        ->assertRedirect()
        ->assertSessionHas('feedback_receipt');

    $complaint = EmployeeServiceFeedback::query()->sole();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($complaint->reference_number)->toStartWith('PSC-')
        ->and(collect($captured)->filter(fn (string $line): bool => str_contains($line, 'notification failed'))->count())->toBeGreaterThanOrEqual(1)
        ->and(collect($captured)->filter(fn (string $line): bool => str_contains($line, RES_NARRATIVE) || str_contains($line, RES_CONTACT) || str_contains($line, $this->a->token->token)))->toHaveCount(0);
});

it('leaves an export queued and visible as such when no worker runs, and refuses to download it', function (): void {
    Queue::fake();
    Storage::fake('local');

    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'csv'])
        ->assertRedirect();

    Queue::assertPushed(ExportPublicFeedbackReport::class, 1);
    $export = PublicFeedbackReportExport::query()->sole();
    expect($export->status)->toBe('queued')->and($export->path)->toBeNull();

    $this->actingAs($this->superAdmin)->get(route('service-feedback.submission-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('exports.0.status', 'queued'));
    $this->actingAs($this->superAdmin)->get(route('service-feedback.submission-reports.download', $export))->assertNotFound();
});

it('writes no narrative, contact or QR secret to the log across every public combination, and answers each request with a correlation id', function (): void {
    $captured = [];
    Log::listen(function (MessageLogged $event) use (&$captured): void {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    $urls = [];
    foreach (['feedback', 'suggestion', 'complaint'] as $type) {
        $urls[] = '/service-feedback/'.$this->a->token->token.'/'.$type;
        $urls[] = '/feedback/'.$this->orgToken.'/'.$type;
    }

    foreach ($urls as $url) {
        $landing = $this->get($url)->assertOk();
        expect($landing->headers->get(RequestCorrelationId::HEADER))->not->toBeEmpty();

        $payload = ['message' => RES_NARRATIVE, 'client_contact' => RES_CONTACT];
        if (str_ends_with($url, '/feedback')) {
            $payload += ['rating' => 3, 'position_service_id' => str_contains($url, '/service-feedback/') ? $this->a->positionService->id : null];
        }
        $posted = $this->post($url, array_filter($payload, fn ($v) => $v !== null))->assertRedirect();
        expect($posted->headers->get(RequestCorrelationId::HEADER))->not->toBeEmpty();
    }

    expect(EmployeeServiceFeedback::query()->count())->toBe(6);

    $leaks = collect($captured)->filter(fn (string $line): bool => str_contains($line, RES_NARRATIVE)
        || str_contains($line, RES_CONTACT)
        || str_contains($line, $this->a->token->token)
        || str_contains($line, $this->orgToken));
    expect($leaks->all())->toBe([]);
});
