<?php

use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('response number increments per case number', function () {
    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Civil',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('court_cases')->insert([
        'case_number' => 'CASE-100',
        'title' => 'Sample case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respondent = Respondent::create([
        'first_name' => 'Ruth',
        'middle_name' => 'K',
        'last_name' => 'Bekele',
        'gender' => 'female',
        'position' => 'Manager',
        'organization_name' => 'Example Org',
        'address' => 'Addis Ababa',
        'phone' => '0911223344',
        'email' => 'ruth@example.com',
        'password' => Hash::make('password'),
    ]);

    $first = DB::transaction(function () use ($respondent) {
        DB::table('court_cases')
            ->where('case_number', 'CASE-100')
            ->lockForUpdate()
            ->first();

        return RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => 'CASE-100',
            'response_number' => RespondentResponse::nextResponseNumberForCase('CASE-100'),
            'title' => 'First response',
            'description' => null,
            'pdf_path' => 'respondent/responses/first.pdf',
        ]);
    });

    $second = DB::transaction(function () use ($respondent) {
        DB::table('court_cases')
            ->where('case_number', 'CASE-100')
            ->lockForUpdate()
            ->first();

        return RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => 'CASE-100',
            'response_number' => RespondentResponse::nextResponseNumberForCase('CASE-100'),
            'title' => 'Second response',
            'description' => null,
            'pdf_path' => 'respondent/responses/second.pdf',
        ]);
    });

    $third = DB::transaction(function () use ($respondent) {
        DB::table('court_cases')
            ->where('case_number', 'CASE-100')
            ->lockForUpdate()
            ->first();

        return RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => 'CASE-100',
            'response_number' => RespondentResponse::nextResponseNumberForCase('CASE-100'),
            'title' => 'Third response',
            'description' => null,
            'pdf_path' => 'respondent/responses/third.pdf',
        ]);
    });

    expect($first->response_number)->toBe("\u{1218}/CASE-100")
        ->and($second->response_number)->toBe("\u{1218}/CASE-100/01")
        ->and($third->response_number)->toBe("\u{1218}/CASE-100/02");
});
