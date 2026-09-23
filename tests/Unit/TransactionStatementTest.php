<?php

use App\Exports\Cafeteria\TransactionStatementExport;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use App\Services\Cafeteria\TransactionStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

uses(TestCase::class);

it('resolves complete calendar periods including leap years and cross-year weeks', function ($period, $date, $start, $end) {
    $filters = app(TransactionStatementService::class)->filters(Request::create('/', 'GET', compact('period', 'date')));
    expect($filters['start_date'])->toBe($start)->and($filters['end_date'])->toBe($end);
})->with([
    ['daily', '2026-09-22', '2026-09-22', '2026-09-22'],
    ['weekly', '2026-01-01', '2025-12-29', '2026-01-04'],
    ['monthly', '2024-02-10', '2024-02-01', '2024-02-29'],
    ['yearly', '2024-06-10', '2024-01-01', '2024-12-31'],
    ['all', '2026-09-22', null, null],
]);

it('rejects invalid dates', function () {
    app(TransactionStatementService::class)->filters(Request::create('/', 'GET', ['date' => '2026-02-31']));
})->throws(ValidationException::class);

it('scopes statements and counts accepted amounts only without subtracting reversals twice', function () {
    // Dedicated in-memory connection: never migrate or modify the application database.
    config(['database.connections.statement_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $schema = Schema::connection('statement_test');
    $schema->create('cafeteria_transactions', function (Blueprint $table) {
        $table->string('id');
        $table->string('cafeteria_provider_id');
        $table->date('transaction_date');
        $table->string('status');
        $table->boolean('is_extra_scan');
        foreach (['meal_amount', 'subsidy_amount_applied', 'employee_payable_amount', 'deduction_amount'] as $column) {
            $table->decimal($column, 12, 2);
        }
    });
    foreach ([['a', 'accepted', 'p1'], ['b', 'reversed', 'p1'], ['c', 'rejected', 'p1'], ['d', 'accepted', 'p2']] as [$id, $status, $provider]) {
        DB::connection('statement_test')->table('cafeteria_transactions')->insert(['id' => $id, 'cafeteria_provider_id' => $provider, 'transaction_date' => '2026-09-22', 'status' => $status, 'is_extra_scan' => false, 'meal_amount' => 100, 'subsidy_amount_applied' => 80, 'employee_payable_amount' => 20, 'deduction_amount' => 0]);
    }
    $access = Mockery::mock(CafeteriaProviderAccessService::class);
    $access->shouldReceive('canAccessAllProviders')->andReturn(false);
    $access->shouldReceive('accessibleProviderIds')->andReturn(['p1'], []);
    app()->instance(CafeteriaProviderAccessService::class, $access);
    $service = app(TransactionStatementService::class);
    $filters = ['provider_id' => '', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => '', 'extra_only' => ''];
    $query = $service->query(new User, $filters);
    $isolated = CafeteriaTransaction::on('statement_test')->mergeConstraintsFrom($query);
    $summary = $service->summary($isolated);
    expect($summary['total'])->toBe(3)->and($summary['accepted'])->toBe(1)->and($summary['subsidy'])->toBe(80.0)->and($summary['meals'])->toBe(100.0);
    $denied = $service->query(new User, $filters);
    expect($denied->toSql())->toContain('0 = 1');
    DB::purge('statement_test');
});

it('renders a printable PDF and numeric Excel totals with safe text cells', function () {
    $txn = new CafeteriaTransaction(['transaction_number' => '=1+1', 'transaction_date' => '2026-09-22', 'status' => 'accepted', 'meal_amount' => 100, 'subsidy_amount_applied' => 80, 'employee_payable_amount' => 20, 'deduction_amount' => 0]);
    $txn->setRelation('employee', null)->setRelation('provider', null);
    $rows = [['number' => '=1+1', 'date' => 'September 22, 2026', 'employee_name' => '', 'employee_number' => '', 'provider' => 'Test Cafeteria', 'status' => 'Accepted', 'meal_amount' => 100.0, 'subsidy_amount_applied' => 80.0, 'employee_payable_amount' => 20.0, 'deduction_amount' => 0.0]];
    $data = ['transactions' => collect([$txn]), 'rows' => $rows, 'summary' => ['total' => 1, 'accepted' => 1, 'meals' => 100, 'subsidy' => 80, 'employee_payable' => 20, 'deductions' => 0], 'filters' => ['status' => '', 'extra_only' => ''], 'locale' => 'en', 'statusLabel' => 'All statuses', 'providerName' => 'Test Cafeteria', 'periodLabel' => 'September 1, 2026 — September 30, 2026', 'actor' => 'Test Operator', 'generatedAt' => '2026-09-22'];
    $pdf = Pdf::loadView('cafeteria.exports.transaction-statement', $data)->setPaper('a4', 'landscape')->output();
    expect($pdf)->toStartWith('%PDF');
    $export = new TransactionStatementExport($data);
    $bytes = Excel::raw($export, Maatwebsite\Excel\Excel::XLSX);
    expect(substr($bytes, 0, 2))->toBe('PK');
    $book = new Spreadsheet;
    $cell = $book->getActiveSheet()->getCell('A1');
    $export->bindValue($cell, '=1+1');
    expect($cell->getDataType())->toBe('s')->and($export->array()[9][5])->toBe(100.0);
});

it('renders the statement in Amharic when the export locale is am', function () {
    app()->setLocale('am');
    $rows = [['number' => 'TXN-1', 'date' => 'መስከረም 12 ፻ 2019', 'employee_name' => 'አበባ', 'employee_number' => 'E-1', 'provider' => 'የሄድስ ካፍቴሪያ', 'status' => __('provider-portal.status_accepted'), 'meal_amount' => 100.0, 'subsidy_amount_applied' => 80.0, 'employee_payable_amount' => 20.0, 'deduction_amount' => 0.0]];
    $data = ['transactions' => collect(), 'rows' => $rows, 'summary' => ['total' => 1, 'accepted' => 1, 'meals' => 100, 'subsidy' => 80, 'employee_payable' => 20, 'deductions' => 0], 'filters' => ['status' => 'accepted', 'extra_only' => ''], 'locale' => 'am', 'statusLabel' => __('provider-portal.status_accepted'), 'providerName' => 'የሄድስ ካፍቴሪያ', 'periodLabel' => 'መስከረም 1 ፻ 2019', 'actor' => 'ተገላች', 'generatedAt' => '2026-09-22'];

    $sheet = (new TransactionStatementExport($data))->array();

    // Headers, filter label and row status all come back Amharic - nothing falls back to English.
    expect($sheet[0][0])->toBe(__('cafeteria-statement.title'))
        ->and($sheet[4][1])->toBe(__('provider-portal.status_accepted'))
        ->and($sheet[8][0])->toBe(__('cafeteria-statement.number'))
        ->and($sheet[9][4])->toBe(__('provider-portal.status_accepted'))
        ->and($sheet[9][3])->toBe('የሄድስ ካፍቴሪያ');

    expect(Pdf::loadView('cafeteria.exports.transaction-statement', $data)->setPaper('a4', 'landscape')->output())->toStartWith('%PDF');
});

it('has an Amharic label for every cafeteria transaction status', function (string $status) {
    $key = 'provider-portal.status_'.$status;
    foreach (['en', 'am'] as $locale) {
        app()->setLocale($locale);
        expect(__($key))->not->toBe($key);
    }
})->with(['accepted', 'rejected', 'reversed', 'pending_review']);
