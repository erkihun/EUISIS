<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public submit throttle (brief §37–38), counting STORED submissions.
 *
 * The framework's `throttle` middleware counts every POST, so a citizen who
 * pressed Send three times with a missing name or an unreadable phone number
 * was locked out for ten minutes before a single word was stored. Here the
 * configured ceilings (PublicFeedbackSettings: per QR code and address, and
 * per address) are spent only by a POST that ends in a stored submission —
 * a validation refusal costs nothing. A flood of invalid POSTs is still
 * stopped: every attempt counts against a wider ceiling, a multiple of the
 * same configured limits, so a script cannot probe a form for free.
 *
 * The thresholds come from the `service-feedback-submit` limiter registered
 * by FeedbackServiceProvider, so the policy stays in one place; keys are
 * RateLimiter keys on the shared cache store, as in the framework middleware.
 */
final class ThrottlePublicSubmissions
{
    public const LIMITER = 'service-feedback-submit';

    /** Attempts (stored or refused) allowed per ceiling, as a multiple of the stored-submission ceiling. */
    public const ATTEMPT_MULTIPLIER = 4;

    public function handle(Request $request, Closure $next): Response
    {
        $limits = $this->limits($request);

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)
                || RateLimiter::tooManyAttempts($this->attemptKey($limit), $limit->maxAttempts * self::ATTEMPT_MULTIPLIER)) {
                throw $this->refusal($limit);
            }
        }

        foreach ($limits as $limit) {
            RateLimiter::hit($this->attemptKey($limit), $limit->decaySeconds);
        }

        $response = $next($request);

        // The controller flashes this only after the row is committed; a refusal, a closed link or a revoked token never sets it.
        if ($request->hasSession() && $request->session()->get('feedback_submitted') === true) {
            foreach ($limits as $limit) {
                RateLimiter::hit($limit->key, $limit->decaySeconds);
            }
        }

        $tightest = $this->tightest($limits);

        if ($tightest !== null) {
            $response->headers->set('X-RateLimit-Limit', (string) $tightest->maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $tightest->maxAttempts - RateLimiter::attempts($tightest->key)));
        }

        return $response;
    }

    /** @return list<Limit> */
    private function limits(Request $request): array
    {
        $limiter = RateLimiter::limiter(self::LIMITER);
        $limits = $limiter === null ? [] : $limiter($request);
        $limits = $limits instanceof Limit ? [$limits] : (array) $limits;

        return array_values(array_filter($limits, fn (mixed $limit): bool => $limit instanceof Limit));
    }

    private function attemptKey(Limit $limit): string
    {
        return $limit->key.':attempts';
    }

    private function refusal(Limit $limit): ThrottleRequestsException
    {
        $retryAfter = max(
            RateLimiter::availableIn($limit->key),
            RateLimiter::availableIn($this->attemptKey($limit)),
            1,
        );

        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $limit->maxAttempts,
            'X-RateLimit-Remaining' => '0',
        ]);
    }

    /** @param list<Limit> $limits */
    private function tightest(array $limits): ?Limit
    {
        $tightest = null;

        foreach ($limits as $limit) {
            $remaining = $limit->maxAttempts - RateLimiter::attempts($limit->key);
            if ($tightest === null || $remaining < $tightest->maxAttempts - RateLimiter::attempts($tightest->key)) {
                $tightest = $limit;
            }
        }

        return $tightest;
    }
}
