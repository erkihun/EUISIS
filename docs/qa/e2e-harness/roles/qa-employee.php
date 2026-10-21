<?php
// Creates a QA employee in the Public Service Bureau with a current assignment and no card,
// so the ID Card Officer journey has someone eligible for a new card.
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;

$psb = '01a0deef-ce1e-7237-89b1-df18a0cdf27c';
$template = EmployeeAssignment::where('organization_id', $psb)->where('is_current', true)->first();
$emp = Employee::where('employee_number', 'QA-CARD-0001')->first();
if (! $emp) {
    $emp = Employee::create([
        'employee_number' => 'QA-CARD-0001', 'first_name' => 'QA', 'last_name' => 'Card Journey',
        'full_name' => 'QA Card Journey', 'phone' => '0911999001', 'status' => 'active', 'is_demo' => true,
    ]);
    $a = EmployeeAssignment::create([
        'employee_id' => $emp->id, 'organization_id' => $psb, 'position_id' => $template->position_id,
        'hierarchy_version_id' => $template->hierarchy_version_id, 'assignment_status' => 'active',
        'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $emp->update(['current_assignment_id' => $a->id]);
}
DB::table('card_requests')->where('employee_id', $emp->id)->delete();
echo $emp->id.' cards='.DB::table('id_cards')->where('employee_id', $emp->id)->count().PHP_EOL;
