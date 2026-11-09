<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Reception\Application\Services\ReceptionAccess;
use Modules\Reception\Contracts\Data\ReceptionPermissions;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReceptionReportController extends Controller
{
    public function __invoke(Request $request, ReceptionAccess $access, WriteAuditLogAction $audit): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can(ReceptionPermissions::EXPORT), 403);
        $query = $access->scope(ReceptionVisit::query(), $user)->with(['visitor:id,name', 'desk:id,code,name_en', 'service:id,name_en'])->oldest('checked_in_at');
        if ($request->date('from')) {
            $query->whereDate('checked_in_at', '>=', $request->date('from'));
        }
        if ($request->date('to')) {
            $query->whereDate('checked_in_at', '<=', $request->date('to'));
        }
        $audit->execute(AuditEventType::ReceptionReportExported, $user, organizationId: null, newValues: ['format' => 'csv', 'filters' => $request->only(['from', 'to'])]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Reference', 'Visitor', 'Desk', 'Service', 'Status', 'Checked in', 'Completed']);
            $query->chunkById(500, function ($rows) use ($out): void {
                foreach ($rows as $v) {
                    fputcsv($out, array_map(self::csvCell(...), [$v->reference, $v->visitor->name, $v->desk->name_en, $v->service?->name_en, $v->status, $v->checked_in_at?->timezone('Africa/Addis_Ababa')->format('Y-m-d H:i'), $v->completed_at?->timezone('Africa/Addis_Ababa')->format('Y-m-d H:i')]));
                }
            });
            fclose($out);
        }, 'reception-visits-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
