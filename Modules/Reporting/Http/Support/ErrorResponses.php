<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Support;

use Illuminate\Http\Request;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingPaused;
use Modules\Reporting\Application\Support\StaleVersion;
use Modules\Reporting\Application\Support\UploadRejected;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * How the module's own exceptions become HTTP responses, in the reader's
 * language. Pages get a redirect back with the message; the autosave and
 * upload calls (JSON) get the status code and the message. A refusal of
 * access becomes the application's ordinary 403.
 *
 * Used by ReportingController::callAction, so the rules stay free of HTTP and
 * the application's global exception handler needs no knowledge of the module.
 */
final class ErrorResponses
{
    public const HANDLED = [ReportingAccessDenied::class, ReportingPaused::class, StaleVersion::class, UploadRejected::class, WorkflowViolation::class];

    public static function for(Throwable $e, Request $request): Response
    {
        return match (true) {
            $e instanceof ReportingAccessDenied => abort(403, (string) __('reporting::messages.errors.denied')),
            $e instanceof ReportingPaused => self::respond($request, 503, (string) __('reporting::messages.errors.paused')),
            $e instanceof StaleVersion => self::respond($request, 409, (string) __('reporting::messages.errors.stale'), ['lock_version' => $e->currentLockVersion]),
            $e instanceof UploadRejected => self::respond($request, 422, (string) __("reporting::messages.upload.{$e->reason}"), ['reason' => $e->reason], 'file'),
            $e instanceof WorkflowViolation => self::respond($request, 422, (string) __("reporting::messages.errors.{$e->reason}", $e->context), ['reason' => $e->reason]),
            default => throw $e,
        };
    }

    /** @param array<string, mixed> $extra */
    private static function respond(Request $request, int $status, string $message, array $extra = [], string $field = 'workflow'): Response
    {
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message, ...$extra], $status);
        }

        return back()->withErrors([$field => $message])->withInput();
    }
}
