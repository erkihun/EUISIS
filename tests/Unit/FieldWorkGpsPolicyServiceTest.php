<?php

declare(strict_types=1);

use App\Services\FieldWork\FieldWorkGpsPolicyService;
use App\Services\SystemSettings\SystemSettingsService;

beforeEach(function (): void {
    $this->policy = new FieldWorkGpsPolicyService(Mockery::mock(SystemSettingsService::class));
});

it('records no enforcement result when policy is not configured', function (): void {
    $result = $this->policy->evaluate([
        'max_accuracy_meters' => null,
        'low_accuracy_action' => 'not_configured',
        'outside_geofence_action' => 'not_configured',
    ], 250.0, true);

    expect($result)->toMatchArray(['flags' => ['outside_geofence'], 'review_state' => 'not_required', 'blocked' => false, 'reason' => null]);
});

it('requires review for a configured low-accuracy policy without discarding the observation', function (): void {
    $result = $this->policy->evaluate([
        'max_accuracy_meters' => 25,
        'low_accuracy_action' => 'require_review',
        'outside_geofence_action' => 'record_only',
    ], 35.0, false);

    expect($result)->toMatchArray(['flags' => ['low_accuracy'], 'review_state' => 'pending_supervisor_review', 'blocked' => false, 'reason' => null]);
});

it('records but blocks a geofence observation when policy explicitly requires it', function (): void {
    $result = $this->policy->evaluate([
        'max_accuracy_meters' => null,
        'low_accuracy_action' => 'not_configured',
        'outside_geofence_action' => 'block',
    ], null, true);

    expect($result['flags'])->toBe(['outside_geofence'])
        ->and($result['review_state'])->toBe('blocked')
        ->and($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toContain('recorded');
});
