<?php

declare(strict_types=1);

use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationLabels;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationRoomService;
use Illuminate\Http\Request;
use Tests\Support\SituationRoomScenario;

/*
 * Every category computes on the real schema with every grant, with no
 * failed card, and every word it emits exists in English and Amharic.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();
    $this->s->submission($this->s->org['woreda_a1'], 'suggestion');
    $this->s->submission($this->s->org['woreda_b1'], 'feedback');
    $this->viewer = $this->s->viewer(SituationPermissions::all(), 'bureau');
});

/** @return list<string> every translation key a payload refers to */
function srKeys(mixed $value): array
{
    if (is_string($value)) {
        return str_starts_with($value, 'situationRoom.') ? [$value] : [];
    }
    if (! is_array($value)) {
        return [];
    }

    return array_merge(...array_values(array_map('srKeys', $value)), ...[[]]);
}

it('computes every category without a failed card, in any period', function (string $category, string $period): void {
    $room = app(SituationRoomService::class);
    $context = $room->context($this->viewer, Request::create('/', 'GET', ['period' => $period]));
    $result = $room->run($room->analytics(SituationCategory::from($category)), $context);

    $errors = array_values(array_filter($result['panels'], fn (array $p): bool => $p['reason'] === 'ERROR'));
    expect($errors)->toBe([])
        ->and($result['has_errors'])->toBeFalse()
        ->and($result['panels'])->not->toBeEmpty();
})->with(array_column(SituationCategory::cases(), 'value'))->with(['today', 'month', 'year']);

it('emits only keys that exist in English and Amharic', function (string $category): void {
    $room = app(SituationRoomService::class);
    $context = $room->context($this->viewer, Request::create('/', 'GET', ['organization_id' => $this->s->org['woreda_a1']->id]));
    $result = $room->run($room->analytics(SituationCategory::from($category)), $context);
    $dictionary = SituationLabels::dictionary();
    $missing = [];

    foreach (array_unique(srKeys($result['panels'])) as $key) {
        $short = substr($key, strlen('situationRoom.'));
        foreach (['en', 'am'] as $locale) {
            if (! isset($dictionary[$locale][$short]) && ! isset($dictionary[$locale][$short.'.title'])) {
                $missing[] = "{$locale}:{$short}";
            }
        }
    }
    foreach ($result['panels'] as $panel) {
        if ($panel['reason'] !== null && ! isset($dictionary['am']['reasons.'.$panel['reason'].'.title'])) {
            $missing[] = 'reason:'.$panel['reason'];
        }
    }

    expect($missing)->toBe([]);
})->with(array_column(SituationCategory::cases(), 'value'));

it('has a title and description for every category in both languages', function (): void {
    $dictionary = SituationLabels::dictionary();

    foreach (SituationCategory::cases() as $category) {
        foreach (['en', 'am'] as $locale) {
            expect($dictionary[$locale])->toHaveKey("categories.{$category->value}.title")
                ->and($dictionary[$locale])->toHaveKey("categories.{$category->value}.description");
        }
    }
});

it('keeps Transport out of the Situation Room', function (): void {
    expect(array_column(SituationCategory::cases(), 'value'))->not->toContain('transport')
        ->and(json_encode(SituationLabels::dictionary()))->not->toContain('ransport');
});
