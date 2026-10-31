<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Modules\Court\Application\Actions\RecordSystemAuditAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SystemAuditMiddleware
{
    private const EXCLUDED_ROUTES = [
        'court.admin.notifications.count',
    ];

    public function __construct(private RecordSystemAuditAction $recordSystemAudit) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($this->shouldAudit($request)) {
                $this->recordSystemAudit->execute($request, exception: $exception);
            }
            throw $exception;
        }

        if ($this->shouldAudit($request)) {
            $this->recordSystemAudit->execute($request, $response->getStatusCode());
        }

        return $response;
    }

    private function shouldAudit(Request $request): bool
    {
        return ! in_array($request->route()?->getName(), self::EXCLUDED_ROUTES, true);
    }
}
