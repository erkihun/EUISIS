<?php

/*
|--------------------------------------------------------------------------
| Court (administrative tribunal) routes — ADR 0020
|--------------------------------------------------------------------------
| court-ms's web routes under /court with `court.` route names: the public
| court site, the applicant/respondent portals (their own guards) and the
| staff area (a platform account with a court_staff row, ResolveCourtStaff).
| The mobile and integration API is routes/court-api.php.
*/
use Modules\Court\Http\Controllers\Admin\AboutController;
use Modules\Court\Http\Controllers\Admin\ActiveSessionController;
use Modules\Court\Http\Controllers\Admin\AdminNotificationController;
use Modules\Court\Http\Controllers\Admin\NotificationFeedController;
use Modules\Court\Http\Controllers\PortalNotificationFeedController;
use Modules\Court\Http\Controllers\Applicant\EuisisSignInController;
use Modules\Court\Http\Controllers\Admin\AdminRoleSwitchController;
use Modules\Court\Http\Controllers\Admin\AnnouncementController;
use Modules\Court\Http\Controllers\Admin\AppealController;
// Applicant-facing controllers
use Modules\Court\Http\Controllers\Admin\ApplicantController;
use Modules\Court\Http\Controllers\Admin\AuditController;
use Modules\Court\Http\Controllers\Admin\BenchNoteController;
use Modules\Court\Http\Controllers\Admin\EuisisCaseLinkController;
use Modules\Court\Http\Controllers\Admin\EuisisStaffController;
use Modules\Court\Http\Controllers\Admin\CaseController;
use Modules\Court\Http\Controllers\Admin\LawyerController;
use Modules\Court\Http\Controllers\Lawyer\LawyerAuthController;
use Modules\Court\Http\Controllers\Lawyer\LawyerCaseController;
use Modules\Court\Http\Controllers\Admin\CasePartiesController;
use Modules\Court\Http\Controllers\Admin\CaseInspectionFindingController;
use Modules\Court\Http\Controllers\Admin\CaseInspectionRequestController;
use Modules\Court\Http\Controllers\Admin\CaseTypeController;
use Modules\Court\Http\Controllers\Admin\DashboardController;
use Modules\Court\Http\Controllers\Admin\DecisionController;
use Modules\Court\Http\Controllers\Admin\DecisionTemplateController;
use Modules\Court\Http\Controllers\Admin\HearingController;
use Modules\Court\Http\Controllers\Admin\LandingPageController;
use Modules\Court\Http\Controllers\Admin\LanguageController;
use Modules\Court\Http\Controllers\Admin\LetterCategoryController;
use Modules\Court\Http\Controllers\Admin\LetterComposerController;
use Modules\Court\Http\Controllers\Admin\LetterController;
// Admin-facing controllers
use Modules\Court\Http\Controllers\Admin\LetterTemplateController;
use Modules\Court\Http\Controllers\Admin\PerformanceEvaluationController;
use Modules\Court\Http\Controllers\Admin\PerformanceEvaluationSettingController;
use Modules\Court\Http\Controllers\Admin\PermissionController;
use Modules\Court\Http\Controllers\Admin\ProfileController as AdminProfileController;
use Modules\Court\Http\Controllers\Admin\RecordController;
use Modules\Court\Http\Controllers\Admin\ReportController;
use Modules\Court\Http\Controllers\Admin\RolesController;
use Modules\Court\Http\Controllers\Admin\SystemSettingController;
use Modules\Court\Http\Controllers\Admin\TeamController;
use Modules\Court\Http\Controllers\Admin\TermsAndConditionsController;
use Modules\Court\Http\Controllers\Admin\UsersController;
use Modules\Court\Http\Controllers\Applicant\ApplicantAuthController;
use Modules\Court\Http\Controllers\Applicant\ApplicantCaseController;
use Modules\Court\Http\Controllers\Applicant\ApplicantDashboardController;
use Modules\Court\Http\Controllers\Applicant\ApplicantNotificationController;
use Modules\Court\Http\Controllers\Applicant\ApplicantPasswordController;
use Modules\Court\Http\Controllers\Applicant\ApplicantProfileController;
use Modules\Court\Http\Controllers\Applicant\ApplicantResponseReplyController;
use Modules\Court\Http\Controllers\Applicant\ApplicantRoleSwitchController;
use Modules\Court\Http\Controllers\Applicant\ApplicantSessionController;
use Modules\Court\Http\Controllers\Applicant\ApplicantVerificationController;
use Modules\Court\Http\Controllers\PublicHomeController;
use Modules\Court\Http\Controllers\PublicSignageController;
use Modules\Court\Http\Controllers\Respondent\CaseSearchController;
use Modules\Court\Http\Controllers\Respondent\DashboardController as RespondentDashboardController;
use Modules\Court\Http\Controllers\Respondent\NotificationController as RespondentNotificationController;
use Modules\Court\Http\Controllers\Respondent\RespondentAuthController;
use Modules\Court\Http\Controllers\Respondent\ResponseController;
use Modules\Court\Http\Controllers\Respondent\ResponseReplyController;
use Modules\Court\Http\Controllers\SecureFileController;
use Modules\Court\Http\Controllers\TermsDisplayController;
// Localization middleware
use Modules\Court\Http\Controllers\UserManualController;
use Modules\Court\Http\Middleware\UseGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Court\Http\Middleware\ResolveCourtStaff;
use Modules\Court\Http\Middleware\ShareCourtProps;

/*
|--------------------------------------------------------------------------
| All routes wrapped with SetLocale
|--------------------------------------------------------------------------
*/

/*
| Every ID-style route parameter is a numeric primary key. Constraining them
| here makes a malformed segment (e.g. /admin/cases/abc) a clean 404 instead
| of a TypeError 500 from an int-typed controller argument.
*/
$numericRouteParameters = [
    'id', 'caseId', 'case', 'fileId', 'evidenceId', 'hearingId', 'witnessId',
    'about', 'announcement', 'appeal', 'applicant', 'benchNote', 'criterion',
    'decision', 'decisionTemplate', 'doc', 'faq', 'file', 'finding', 'hearing',
    'letter', 'letterCategory', 'letter_template', 'performanceEvaluation',
    'permission', 'reply', 'requestRecord', 'resource', 'response', 'review',
    'role', 'service', 'slide', 'step', 'team', 'term', 'user', 'witness',
];

Route::middleware(ShareCourtProps::class)->prefix('court')->name('court.')->where(array_fill_keys($numericRouteParameters, '[0-9]+'))->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public / Locale
    |--------------------------------------------------------------------------
    */
    // Single canonical language switch route (matches admin layout)
    Route::get('/language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');
    Route::get('/user-manual', UserManualController::class)->name('landing.user-manual');

    Route::get('/home', [PublicHomeController::class, 'home'])->name('landing.home');

    if (app()->environment('local')) {
        Route::get('/debug-locale', fn () => 'locale='.app()->getLocale());
    }
    // With the portal in EUISIS (Flow 6) the court's home is its public site.
    Route::get('/', fn () => redirect()->route(\Modules\Court\Application\Support\EuisisParties::portalClosed() ? 'court.landing.home' : 'court.applicant.login'))->name('root');
    Route::get('/resources', [PublicHomeController::class, 'resources'])->name('public.resources');
    Route::get('/news', [PublicHomeController::class, 'newsIndex'])->name('public.news');
    Route::get('/news/{announcement}', [PublicHomeController::class, 'newsItem'])->whereNumber('announcement')->name('public.news.show');
    Route::get('/terms', [TermsDisplayController::class, 'show'])->name('public.terms');
    Route::get('/signage', [PublicSignageController::class, 'show'])
        ->middleware(['throttle:30,1'])
        ->name('public.signage');
    Route::get('/applicant', fn () => redirect()->route('court.applicant.login'))->name('applicant.login.shortcut');
    Route::get('/respondent', fn () => redirect()->route('court.respondent.login'))->name('respondent.login.shortcut');

    /*
    |--------------------------------------------------------------------------
    | Respondent Public Routes (guest:respondent)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['court.guest:respondent', UseGuard::class.':respondent'])->group(function () {
        Route::get('/respondent/register', fn () => redirect()->route('court.applicant.login', ['login_as' => 'respondent']))->name('respondent.register');
        Route::post('/respondent/register', fn () => redirect()->route('court.applicant.login', ['login_as' => 'respondent']))->name('respondent.register.submit');
        Route::get('/respondent/login', fn () => redirect()->route('court.applicant.login', ['login_as' => 'respondent']))->name('respondent.login');
        Route::post('/respondent/login', fn () => redirect()->route('court.applicant.login', ['login_as' => 'respondent']))->name('respondent.login.submit');
    });

    Route::middleware(['court.auth:applicant', UseGuard::class.':respondent'])->group(function () {
        Route::post('/respondent/logout', [RespondentAuthController::class, 'logout'])->name('respondent.logout');
        Route::get('/respondent/dashboard', [RespondentDashboardController::class, 'index'])->name('respondent.dashboard');
        Route::get('/respondent/case-search/results', [CaseSearchController::class, 'myCases'])->name('respondent.cases.my');
        Route::get('/respondent/responses', [ResponseController::class, 'index'])->name('respondent.responses.index');
        Route::get('/respondent/responses/create', [ResponseController::class, 'create'])->name('respondent.responses.create');
        Route::post('/respondent/responses', [ResponseController::class, 'store'])->name('respondent.responses.store');
        Route::get('/respondent/responses/{response}', [ResponseController::class, 'show'])->name('respondent.responses.show');
        Route::get('/respondent/responses/{response}/edit', [ResponseController::class, 'edit'])->name('respondent.responses.edit');
        Route::patch('/respondent/responses/{response}', [ResponseController::class, 'update'])->name('respondent.responses.update');
        Route::delete('/respondent/responses/{response}', [ResponseController::class, 'destroy'])->name('respondent.responses.destroy');
        Route::get('/respondent/response-replies', [ResponseReplyController::class, 'index'])->name('respondent.response-replies.index');
        Route::get('/respondent/response-replies/{reply}', [ResponseReplyController::class, 'show'])->name('respondent.response-replies.show');
        Route::get('/respondent/response-replies/{reply}/download', [SecureFileController::class, 'respondentApplicantResponseReply'])
            ->name('respondent.response-replies.download');
        Route::get('/respondent/profile', [ApplicantProfileController::class, 'edit'])->name('respondent.profile.edit');
        Route::patch('/respondent/profile', [ApplicantProfileController::class, 'update'])->name('respondent.profile.update');
        Route::patch('/respondent/profile/password', [ApplicantProfileController::class, 'updatePassword'])->name('respondent.profile.password');
        Route::get('/respondent/profile/sessions', [ApplicantSessionController::class, 'index'])->name('respondent.profile.sessions.index');
        Route::delete('/respondent/profile/sessions/{session}', [ApplicantSessionController::class, 'destroy'])->name('respondent.profile.sessions.destroy');
        Route::delete('/respondent/profile/sessions', [ApplicantSessionController::class, 'destroyOthers'])->name('respondent.profile.sessions.destroyOthers');
        Route::post('/respondent/notifications/mark-one', [RespondentNotificationController::class, 'markOne'])->name('respondent.notifications.markOne');
        Route::post('/respondent/notifications/mark-all', [RespondentNotificationController::class, 'markAll'])->name('respondent.notifications.markAll');
        // The header bell (EUISIS NotificationBell).
        Route::get('/respondent/notifications/feed', [PortalNotificationFeedController::class, 'feed'])->name('respondent.notifications.feed');
        Route::post('/respondent/notifications/read-all', [PortalNotificationFeedController::class, 'readAll'])->name('respondent.notifications.read-all');
        Route::post('/respondent/notifications/{notice}/read', [PortalNotificationFeedController::class, 'read'])->name('respondent.notifications.read');
        Route::post('/respondent/switch-to-applicant', [RespondentAuthController::class, 'switchToApplicant'])->name('respondent.switchToApplicant');
    });

    Route::get('/respondent/case-search', [CaseSearchController::class, 'index'])
        ->middleware(['court.auth:applicant', 'throttle:30,1'])
        ->name('respondent.case.search');
    // The search form posts here, so the access code stays out of URLs; 10 tries a minute.
    Route::post('/respondent/case-search', [CaseSearchController::class, 'submit'])
        ->middleware(['court.auth:applicant', 'throttle:10,1'])
        ->name('respondent.case.search.submit');
    Route::get('/respondent/cases/{caseNumber}', [CaseSearchController::class, 'show'])
        ->middleware(['court.auth:applicant', 'throttle:30,1'])
        ->name('respondent.cases.show')
        ->where('caseNumber', '.*');
    Route::get('/respondent/decisions/{decision}/download', [CaseSearchController::class, 'downloadDecision'])
        ->middleware(['court.auth:applicant', 'throttle:30,1'])
        ->name('respondent.decisions.download');

    // Public-facing preview for applicants/respondents (authorization handled in controller)
    Route::get('/case-letters/{letter}', [LetterController::class, 'publicPreview'])
        ->name('letters.case-preview');

    // Legacy "/apply" prefix for email verification links emitted on production domains.
    Route::get('/apply/email/verify', function () {
        return redirect()->route('court.applicant.verification.notice');
    });
    Route::get('/apply/email/verify/{id}/{hash}', function ($id, $hash, Request $request) {
        return redirect()->route(
            'court.applicant.verification.verify',
            array_merge(['id' => $id, 'hash' => $hash], $request->query())
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Applicant Auth (guest:applicant)
    |--------------------------------------------------------------------------
    */
    // From the EUISIS employee portal (Flow 4): a one-time ticket signs the employee in as an applicant.
    Route::get('/applicant/euisis/sign-in/{ticket}', EuisisSignInController::class)
        ->middleware(['throttle:20,1', UseGuard::class.':applicant'])
        ->where('ticket', '[A-Za-z0-9]{64}')
        ->name('applicant.euisis.sign-in');

    // Lawyers (EUISIS ADR 0013 #13): their own sign-in; they then work in the court portal on the cases they represent.
    Route::prefix('/lawyer')->name('lawyer.')->group(function () {
        Route::middleware(['court.guest:applicant', UseGuard::class.':applicant'])->group(function () {
            Route::get('/login', [LawyerAuthController::class, 'showLogin'])->name('login');
            Route::post('/login', [LawyerAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
            // A lawyer applies; the registry verifies the licence before the account opens.
            Route::get('/register', [LawyerAuthController::class, 'showRegister'])->name('register');
            Route::post('/register', [LawyerAuthController::class, 'register'])->middleware('throttle:5,1')->name('register.submit');
            Route::get('/forgot-password', [LawyerAuthController::class, 'showForgot'])->name('password.request');
            Route::post('/forgot-password', [LawyerAuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
            Route::get('/reset-password/{token}', [LawyerAuthController::class, 'showReset'])->name('password.reset');
            Route::post('/reset-password', [LawyerAuthController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
        });
        Route::post('/logout', [LawyerAuthController::class, 'logout'])->middleware('court.auth:applicant')->name('logout');
    });

    Route::middleware(['court.guest:applicant', UseGuard::class.':applicant'])->group(function () {
        // Register
        Route::get('/applicant/register', [ApplicantAuthController::class, 'showRegister'])->name('applicant.register');
        Route::post('/applicant/register', [ApplicantAuthController::class, 'register'])->name('applicant.register.submit');
        Route::get('/applicant/login', [ApplicantAuthController::class, 'showLogin'])->name('applicant.login');
        Route::post('/applicant/login', [ApplicantAuthController::class, 'login'])->name('applicant.login.submit');
        // Password reset — OTP flow
        Route::get('/applicant/forgot-password', [ApplicantPasswordController::class, 'showLinkRequestForm'])->name('applicant.password.request');
        Route::post('/applicant/forgot-password', [ApplicantPasswordController::class, 'sendResetLinkEmail'])->name('applicant.password.email');
        Route::get('/applicant/password-otp', [ApplicantPasswordController::class, 'showOtpForm'])->name('applicant.password.otp.show');
        Route::post('/applicant/password-otp', [ApplicantPasswordController::class, 'verifyOtp'])->name('applicant.password.otp.verify');
        Route::post('/applicant/password-otp/resend', [ApplicantPasswordController::class, 'resendOtp'])->name('applicant.password.otp.resend');
        Route::get('/applicant/new-password', [ApplicantPasswordController::class, 'showNewPasswordForm'])->name('applicant.password.new.show');
        Route::post('/applicant/new-password', [ApplicantPasswordController::class, 'updatePassword'])->name('applicant.password.new.update');

    });

    /*
    |--------------------------------------------------------------------------
    | Applicant Portal (auth:applicant)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['court.auth:applicant', UseGuard::class.':applicant'])->group(function () {
        Route::post('/applicant/logout', [ApplicantAuthController::class, 'logout'])->name('applicant.logout');

        // Email verification (OTP-based)
        Route::get('/applicant/email/verify', [ApplicantVerificationController::class, 'notice'])->name('applicant.verification.notice');
        Route::post('/applicant/email/verify', [ApplicantVerificationController::class, 'verifyEmailOtp'])
            ->middleware('throttle:10,1')->name('applicant.verification.verify-otp');
        Route::post('/applicant/email/verification-notification', [ApplicantVerificationController::class, 'send'])
            ->middleware('throttle:6,1')->name('applicant.verification.send');
        Route::get('/applicant/email/verify/{id}/{hash}', [ApplicantVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])->name('applicant.verification.verify');

        // Registration no longer requires email OTP verification, so the portal
        // is not gated behind `verified` — OTP is used for password reset only.
        Route::middleware([])->group(function () {
            // Dashboard
            Route::get('/applicant/dashboard', [ApplicantDashboardController::class, 'index'])->name('applicant.dashboard');
            Route::get('/applicant/decisions/{decision}/download', [ApplicantDashboardController::class, 'downloadDecision'])
                ->name('applicant.decisions.download');

            // Profile
            Route::get('/applicant/profile', [ApplicantProfileController::class, 'edit'])->name('applicant.profile.edit');
            Route::patch('/applicant/profile', [ApplicantProfileController::class, 'update'])->name('applicant.profile.update');
            Route::patch('/applicant/profile/password', [ApplicantProfileController::class, 'updatePassword'])->name('applicant.profile.password.update');
            Route::get('/applicant/profile/sessions', [ApplicantSessionController::class, 'index'])->name('applicant.profile.sessions.index');
            Route::delete('/applicant/profile/sessions/{session}', [ApplicantSessionController::class, 'destroy'])->name('applicant.profile.sessions.destroy');
            Route::delete('/applicant/profile/sessions', [ApplicantSessionController::class, 'destroyOthers'])->name('applicant.profile.sessions.destroyOthers');
            Route::get('/applicant/profile/lawyer-document', [ApplicantProfileController::class, 'lawyerDocument'])->name('applicant.profile.lawyer-document');
            Route::post('/applicant/switch-to-respondent', ApplicantRoleSwitchController::class)
                ->name('applicant.switchToRespondent');

            // Cases (only applicant's own)
            Route::get('/applicant/cases', [ApplicantCaseController::class, 'index'])->name('applicant.cases.index');
            Route::get('/applicant/cases/create', [ApplicantCaseController::class, 'create'])->name('applicant.cases.create');
            // A verified lawyer files a case for a client (Modules\Court\Http\Controllers\Lawyer\LawyerCaseController).
            Route::get('/applicant/lawyer/file', [LawyerCaseController::class, 'create'])->name('applicant.lawyer.file');
            Route::post('/applicant/lawyer/file', [LawyerCaseController::class, 'store'])->middleware('throttle:10,1')->name('applicant.lawyer.file.store');
            Route::post('/applicant/lawyer/client', [LawyerCaseController::class, 'client'])->middleware('throttle:30,1')->name('applicant.lawyer.client');
            Route::post('/applicant/cases', [ApplicantCaseController::class, 'store'])->name('applicant.cases.store');
            Route::get('/applicant/cases/{id}', [ApplicantCaseController::class, 'show'])->name('applicant.cases.show');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}', [ApplicantCaseController::class, 'showRespondentResponse'])->name('applicant.cases.respondentResponses.show');
            Route::get('/applicant/response-replies', [ApplicantResponseReplyController::class, 'index'])->name('applicant.response-replies.index');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}/reply', [ApplicantResponseReplyController::class, 'create'])->name('applicant.cases.respondentResponses.reply');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}/replies/create', [ApplicantResponseReplyController::class, 'create'])->name('applicant.cases.respondentResponses.replies.create');
            Route::post('/applicant/cases/{case}/respondent-responses/{response}/replies', [ApplicantResponseReplyController::class, 'store'])->name('applicant.cases.respondentResponses.replies.store');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}/replies/{reply}', [ApplicantResponseReplyController::class, 'show'])->name('applicant.cases.respondentResponses.replies.show');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}/replies/{reply}/edit', [ApplicantResponseReplyController::class, 'edit'])->name('applicant.cases.respondentResponses.replies.edit');
            Route::patch('/applicant/cases/{case}/respondent-responses/{response}/replies/{reply}', [ApplicantResponseReplyController::class, 'update'])->name('applicant.cases.respondentResponses.replies.update');
            Route::delete('/applicant/cases/{case}/respondent-responses/{response}/replies/{reply}', [ApplicantResponseReplyController::class, 'destroy'])->name('applicant.cases.respondentResponses.replies.destroy');
            Route::get('/applicant/cases/{id}/edit', [ApplicantCaseController::class, 'edit'])->name('applicant.cases.edit');
            Route::patch('/applicant/cases/{id}', [ApplicantCaseController::class, 'update'])->name('applicant.cases.update');
            Route::delete('/applicant/cases/{id}', [ApplicantCaseController::class, 'destroy'])
                ->name('applicant.cases.destroy');
            // Files
            Route::post('/applicant/cases/{id}/files', [ApplicantCaseController::class, 'uploadFile'])->name('applicant.cases.files.upload');
            Route::delete('/applicant/cases/{id}/files/{fileId}', [ApplicantCaseController::class, 'deleteFile'])->name('applicant.cases.files.delete');
            Route::get('/applicant/cases/{id}/files/{fileId}/download', [SecureFileController::class, 'caseFile'])
                ->name('applicant.cases.files.download');

            // Evidences / Witnesses
            Route::delete('/applicant/cases/{id}/evidences/{evidenceId}', [ApplicantCaseController::class, 'deleteEvidence'])->name('applicant.cases.evidences.delete');
            Route::get('/applicant/cases/{id}/evidences/{evidenceId}/download', [SecureFileController::class, 'caseEvidence'])
                ->name('applicant.cases.evidences.download');
            Route::delete('/applicant/cases/{id}/witnesses/{witnessId}', [ApplicantCaseController::class, 'deleteWitness'])->name('applicant.cases.witnesses.delete');

            // Messages
            Route::post('/applicant/cases/{id}/messages', [ApplicantCaseController::class, 'postMessage'])->name('applicant.cases.messages.post');

            // Receipts / exports
            Route::get('/applicant/cases/{id}/receipt', [ApplicantCaseController::class, 'receipt'])->name('applicant.cases.receipt');
            Route::get('/applicant/cases/{id}/receipt/pdf', [ApplicantCaseController::class, 'receiptPdf'])->name('applicant.cases.receipt.pdf');
            Route::post('/applicant/cases/{id}/receipt/email', [ApplicantCaseController::class, 'emailReceipt'])->name('applicant.cases.receipt.email');

            // Hearing ICS
            Route::get('/applicant/cases/{id}/hearings/{hearingId}/ics', [ApplicantCaseController::class, 'downloadHearingIcs'])->name('applicant.cases.hearings.ics');

            // Respondent / client actions (case search + responses)
            Route::middleware('throttle:30,1')->group(function () {
                Route::get('/applicant/respondent/case-search', [CaseSearchController::class, 'index'])
                    ->name('applicant.respondent.cases.search');
                // Before the catch-all case route below, which would otherwise read "my" as a case number.
                Route::get('/applicant/respondent/cases/my', [CaseSearchController::class, 'myCases'])
                    ->name('applicant.respondent.cases.my');
                Route::get('/applicant/respondent/cases/{caseNumber}', [CaseSearchController::class, 'show'])
                    ->name('applicant.respondent.cases.show')
                    ->where('caseNumber', '.*');
            });

            Route::get('/applicant/respondent/responses', [ResponseController::class, 'index'])
                ->name('applicant.respondent.responses.index');
            Route::get('/applicant/respondent/responses/create', [ResponseController::class, 'create'])
                ->name('applicant.respondent.responses.create');
            Route::post('/applicant/respondent/responses', [ResponseController::class, 'store'])
                ->name('applicant.respondent.responses.store');
            Route::get('/applicant/respondent/responses/{response}', [ResponseController::class, 'show'])
                ->name('applicant.respondent.responses.show');
            Route::get('/applicant/respondent/responses/{response}/edit', [ResponseController::class, 'edit'])
                ->name('applicant.respondent.responses.edit');
            Route::patch('/applicant/respondent/responses/{response}', [ResponseController::class, 'update'])
                ->name('applicant.respondent.responses.update');
            Route::delete('/applicant/respondent/responses/{response}', [ResponseController::class, 'destroy'])
                ->name('applicant.respondent.responses.destroy');
            Route::get('/applicant/respondent/responses/{response}/download', [SecureFileController::class, 'respondentResponse'])
                ->name('applicant.respondent.responses.download');
            Route::get('/applicant/cases/{case}/respondent-responses/{response}/replies/{reply}/download', [SecureFileController::class, 'applicantResponseReply'])
                ->name('applicant.cases.respondentResponses.replies.download');

            // Notifications
            Route::get('/applicant/notifications', [ApplicantNotificationController::class, 'index'])->name('applicant.notifications.index');
            Route::post('/applicant/notifications/mark-one', [ApplicantNotificationController::class, 'markOne'])->name('applicant.notifications.markOne');
            Route::post('/applicant/notifications/mark-all', [ApplicantNotificationController::class, 'markAll'])->name('applicant.notifications.markAll');
            // The header bell (EUISIS NotificationBell).
            Route::get('/applicant/notifications/feed', [PortalNotificationFeedController::class, 'feed'])->name('applicant.notifications.feed');
            Route::post('/applicant/notifications/read-all', [PortalNotificationFeedController::class, 'readAll'])->name('applicant.notifications.read-all');
            Route::post('/applicant/notifications/{notice}/read', [PortalNotificationFeedController::class, 'read'])->name('applicant.notifications.read');

            // Notification settings
            Route::get('/applicant/notifications/settings', [ApplicantNotificationController::class, 'settingsEdit'])->name('applicant.notifications.settings');
            Route::post('/applicant/notifications/settings', [ApplicantNotificationController::class, 'settingsUpdate'])->name('applicant.notifications.settings.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Admin area (auth:web)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth', ResolveCourtStaff::class, 'court.active-role'])->group(function () {
        // Admin dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::post('/admin/role/switch', AdminRoleSwitchController::class)
            ->name('admin.roles.switch');

        // Admin self profile
        Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [AdminProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [AdminProfileController::class, 'destroy'])->name('profile.destroy');

        // Admin section with /admin prefix
        Route::prefix('admin')->group(function () {
            Route::get('/', fn () => redirect()->route('court.admin.dashboard'))
                ->name('admin.home');

            Route::get('/dashboard', [DashboardController::class, 'index'])
                ->name('admin.dashboard');

            Route::get('/reports', [ReportController::class, 'index'])
                ->middleware('court.perm:reports.view')
                ->name('reports.index');

            // Dashboard stats (AJAX)
            Route::get('/dashboard/stats', [DashboardController::class, 'stats'])
                ->name('admin.dashboard.stats');

            // System settings: the EUISIS settings page (SystemSettings/Index); each section saves on its own,
            // under the route names that page uses (system-settings.*).
            Route::middleware('court.perm:settings.manage')->group(function () {
                Route::get('/settings/system', [SystemSettingController::class, 'edit'])->name('settings.system.edit');
                foreach (['general', 'localization', 'appearance', 'notifications', 'email', 'sms', 'telegram', 'security'] as $section) {
                    Route::patch('/settings/system/'.$section, [SystemSettingController::class, 'updateSection'])
                        ->defaults('section', $section)
                        ->name('system-settings.'.$section.'.update');
                }
                Route::post('/settings/system/clear-cache', [SystemSettingController::class, 'clearCache'])->name('system-settings.clear-cache');
                Route::post('/settings/system/test-email', [SystemSettingController::class, 'testEmail'])->middleware('throttle:6,1')->name('system-settings.test-email');
                Route::post('/settings/system/test-telegram', [SystemSettingController::class, 'testTelegram'])->middleware('throttle:6,1')->name('system-settings.test-telegram');
                Route::get('/settings/system/maintenance', [SystemSettingController::class, 'maintenance'])->name('settings.maintenance');
                Route::post('/settings/system/database-backup', [SystemSettingController::class, 'downloadDatabaseBackup'])->name('settings.system.databaseBackup');
            });

            Route::get('/settings/performance-evaluation', [PerformanceEvaluationSettingController::class, 'index'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.index');
            Route::get('/settings/performance-evaluation/criteria/create', [PerformanceEvaluationSettingController::class, 'createCriterion'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.criteria.create');
            Route::post('/settings/performance-evaluation/criteria', [PerformanceEvaluationSettingController::class, 'store'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.criteria.store');
            Route::get('/settings/performance-evaluation/categories/create', [PerformanceEvaluationSettingController::class, 'createCategory'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.categories.create');
            Route::post('/settings/performance-evaluation/categories', [PerformanceEvaluationSettingController::class, 'storeCategory'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.categories.store');
            Route::patch('/settings/performance-evaluation/criteria/{criterion}', [PerformanceEvaluationSettingController::class, 'update'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.criteria.update');
            Route::delete('/settings/performance-evaluation/criteria/{criterion}', [PerformanceEvaluationSettingController::class, 'destroy'])
                ->middleware('court.perm:settings.manage')
                ->name('settings.performance-evaluation.criteria.destroy');

            // Landing page manager: the public home page is part of the system settings.
            Route::middleware('court.perm:settings.manage')->group(function () {
                Route::get('/landing', [LandingPageController::class, 'index'])->name('admin.landing.index');
                Route::post('/landing/slides', [LandingPageController::class, 'storeSlide'])->name('admin.landing.slides.store');
                Route::put('/landing/slides/{slide}/update', [LandingPageController::class, 'updateSlide'])->name('admin.landing.slides.update');
                Route::delete('/landing/slides/{slide}', [LandingPageController::class, 'destroySlide'])->name('admin.landing.slides.destroy');
                Route::patch('/landing/slides/{slide}/toggle', [LandingPageController::class, 'toggleSlide'])->name('admin.landing.slides.toggle');
                Route::post('/landing/slides/reorder', [LandingPageController::class, 'reorderSlides'])->name('admin.landing.slides.reorder');
                Route::post('/landing/faqs', [LandingPageController::class, 'storeFaq'])->name('admin.landing.faqs.store');
                Route::put('/landing/faqs/{faq}/update', [LandingPageController::class, 'updateFaq'])->name('admin.landing.faqs.update');
                Route::delete('/landing/faqs/{faq}', [LandingPageController::class, 'destroyFaq'])->name('admin.landing.faqs.destroy');
                Route::patch('/landing/faqs/{faq}/toggle', [LandingPageController::class, 'toggleFaq'])->name('admin.landing.faqs.toggle');
                Route::put('/landing/sections', [LandingPageController::class, 'updateSections'])->name('admin.landing.sections.update');
                Route::put('/landing/metrics', [LandingPageController::class, 'updateMetrics'])->name('admin.landing.metrics.update');
                Route::post('/landing/services', [LandingPageController::class, 'storeService'])->name('admin.landing.services.store');
                Route::put('/landing/services/{service}/update', [LandingPageController::class, 'updateService'])->name('admin.landing.services.update');
                Route::delete('/landing/services/{service}', [LandingPageController::class, 'destroyService'])->name('admin.landing.services.destroy');
                Route::patch('/landing/services/{service}/toggle', [LandingPageController::class, 'toggleService'])->name('admin.landing.services.toggle');
                // Timeline steps
                Route::post('/landing/steps', [LandingPageController::class, 'storeStep'])->name('admin.landing.steps.store');
                Route::put('/landing/steps/{step}/update', [LandingPageController::class, 'updateStep'])->name('admin.landing.steps.update');
                Route::delete('/landing/steps/{step}', [LandingPageController::class, 'destroyStep'])->name('admin.landing.steps.destroy');
                Route::patch('/landing/steps/{step}/toggle', [LandingPageController::class, 'toggleStep'])->name('admin.landing.steps.toggle');
                // Resources
                Route::post('/landing/resources', [LandingPageController::class, 'storeResource'])->name('admin.landing.resources.store');
                Route::put('/landing/resources/{resource}/update', [LandingPageController::class, 'updateResource'])->name('admin.landing.resources.update');
                Route::delete('/landing/resources/{resource}', [LandingPageController::class, 'destroyResource'])->name('admin.landing.resources.destroy');
                Route::patch('/landing/resources/{resource}/toggle', [LandingPageController::class, 'toggleResource'])->name('admin.landing.resources.toggle');
                Route::put('/landing/user-manual', [LandingPageController::class, 'updateUserManual'])->name('admin.landing.user-manual.update');
                // Footer settings
                Route::put('/landing/footer', [LandingPageController::class, 'updateFooter'])->name('admin.landing.footer.update');
                Route::put('/landing/about', [LandingPageController::class, 'updateAbout'])->name('admin.landing.about.update');
                Route::post('/landing/members', [LandingPageController::class, 'storeMember'])->name('admin.landing.members.store');
                Route::put('/landing/members/{member}/update', [LandingPageController::class, 'updateMember'])->name('admin.landing.members.update');
                Route::delete('/landing/members/{member}', [LandingPageController::class, 'destroyMember'])->name('admin.landing.members.destroy');
                Route::patch('/landing/members/{member}/toggle', [LandingPageController::class, 'toggleMember'])->name('admin.landing.members.toggle');
                Route::post('/landing/members/reorder', [LandingPageController::class, 'reorderMembers'])->name('admin.landing.members.reorder');
            });

            // System audit (read-only view)
            Route::get('/audit', [AuditController::class, 'index'])
                ->middleware('court.perm:audit.view')
                ->name('admin.audit');

            /*
             * Cases (admin)
             */
            Route::patch('/cases/{id}/review', [CaseController::class, 'reviewDecision'])
                ->middleware('court.perm:cases.review')
                ->name('cases.review.update');

            Route::patch('/respondent-responses/{response}/review', [CaseController::class, 'reviewRespondentResponse'])
                ->middleware('court.perm:cases.review')
                ->name('respondent-responses.review');
            Route::patch('/applicant-response-replies/{reply}/review', [CaseController::class, 'reviewApplicantResponseReply'])
                ->middleware('court.perm:cases.response-replies.manage')
                ->name('applicant-response-replies.review');

            Route::get('/cases', [CaseController::class, 'index'])->middleware('court.perm:cases.view')->name('cases.index');
            Route::get('/cases/export', [CaseController::class, 'export'])->middleware('court.perm:reports.export')->name('cases.export');
            Route::get('/cases/{id}', [CaseController::class, 'show'])->middleware('court.perm:cases.view')->name('cases.show');
            // EUISIS links on the case page (docs/euisis-integration.md).
            Route::patch('/cases/{id}/euisis/organization', [EuisisCaseLinkController::class, 'organization'])
                ->middleware(['court.perm:cases.edit', 'throttle:30,1'])->whereNumber('id')->name('cases.euisis.organization');
            Route::post('/cases/{id}/euisis/employee', [EuisisCaseLinkController::class, 'employee'])
                ->middleware(['court.perm:cases.edit', 'throttle:20,1'])->whereNumber('id')->name('cases.euisis.employee');
            Route::get('/cases/{case}/documents/{doc}', [CaseController::class, 'viewDocument'])->middleware('court.perm:cases.view')->name('cases.documents.view');
            Route::get('/cases/{case}/lawyer-document', [SecureFileController::class, 'lawyerDocument'])->middleware('court.perm:cases.view')->name('cases.lawyer-document');

            Route::get('/cases/{caseId}/assign', [CaseController::class, 'assignForm'])
                ->middleware('court.perm:cases.assign')
                ->name('cases.assign.form');
            Route::patch('/cases/{caseId}/assign', [CaseController::class, 'assignUpdate'])
                ->middleware('court.perm:cases.assign')
                ->name('cases.assign.update');

            Route::patch('/cases/{id}/status', [CaseController::class, 'updateStatus'])->middleware('court.perm:cases.edit')->name('cases.status.update');
            Route::post('/cases/{id}/messages', [CaseController::class, 'postAdminMessage'])->middleware('court.perm:cases.edit')->name('cases.messages.post');
            // Parties and representation (Modules\Court\Application\Services\CaseParties): the judge decides an intervention inside the controller.
            Route::post('/cases/{id}/parties/{party}/decision', [CasePartiesController::class, 'decide'])->middleware(['court.perm:cases.view', 'throttle:30,1'])->whereNumber(['id', 'party'])->name('cases.parties.decide');
            Route::get('/cases/{id}/parties/{party}/attachment', [CasePartiesController::class, 'attachment'])->middleware('court.perm:cases.view')->whereNumber(['id', 'party'])->name('cases.parties.attachment');
            Route::post('/cases/{id}/representatives/{representative}/verification', [CasePartiesController::class, 'verify'])->middleware(['court.perm:cases.edit', 'throttle:30,1'])->whereNumber(['id', 'representative'])->name('cases.representatives.verify');
            Route::post('/cases/{id}/parties/{party}/representatives', [CasePartiesController::class, 'addRepresentative'])->middleware(['court.perm:cases.edit', 'throttle:20,1'])->whereNumber(['id', 'party'])->name('cases.parties.representatives.store');
            Route::get('/cases/{id}/representatives/{representative}/power-of-attorney', [CasePartiesController::class, 'powerOfAttorney'])->middleware('court.perm:cases.view')->whereNumber(['id', 'representative'])->name('cases.representatives.power-of-attorney');
            Route::post('/cases/{id}/merge', [CasePartiesController::class, 'merge'])->middleware(['court.perm:cases.merge', 'throttle:10,1'])->whereNumber('id')->name('cases.merge');

            // Registrar review (accept/reject) - route used by index blade
            Route::post('/cases/review', [CaseController::class, 'review'])->middleware('court.perm:cases.review')->name('cases.review');

            // Hearings
            Route::post('/cases/{case}/hearings', [CaseController::class, 'storeHearing'])->middleware('court.perm:cases.edit')->name('cases.hearings.store');
            Route::patch('/hearings/{hearing}', [CaseController::class, 'updateHearing'])->middleware('court.perm:cases.edit')->name('cases.hearings.update');
            Route::delete('/hearings/{hearing}', [CaseController::class, 'deleteHearing'])->middleware('court.perm:cases.edit')->name('cases.hearings.delete');
            Route::get('/hearings', [HearingController::class, 'index'])->middleware('court.perm:cases.view')->name('admin.hearings.index');

            // Files
            Route::post('/cases/{case}/files', [CaseController::class, 'storeFile'])->middleware('court.perm:cases.edit')->name('cases.files.upload');
            Route::delete('/cases/{case}/files/{file}', [CaseController::class, 'deleteFile'])->middleware('court.perm:cases.edit')->name('cases.files.delete');
            Route::get('/cases/{case}/files/{file}/download', [SecureFileController::class, 'caseFile'])
                ->middleware('court.perm:cases.view')
                ->name('cases.files.download');
            Route::get('/respondent-responses/{response}/download', [SecureFileController::class, 'respondentResponse'])
                ->middleware('court.perm:cases.view')
                ->name('respondent-responses.download');
            Route::get('/cases/{case}/respondent-responses/{response}/replies/{reply}/download', [SecureFileController::class, 'applicantResponseReply'])
                ->middleware('court.perm:cases.response-replies.manage')
                ->name('admin.applicant-response-replies.download');

            // Witnesses
            Route::post('/cases/{case}/witnesses', [CaseController::class, 'storeWitness'])->middleware('court.perm:cases.edit')->name('cases.witnesses.store');
            Route::patch('/cases/{case}/witnesses/{witness}', [CaseController::class, 'updateWitness'])->middleware('court.perm:cases.edit')->name('cases.witnesses.update');
            Route::delete('/cases/{case}/witnesses/{witness}', [CaseController::class, 'deleteWitness'])->middleware('court.perm:cases.edit')->name('cases.witnesses.delete');

            Route::middleware('court.perm:bench-notes.manage')->group(function () {
                Route::get('/bench-notes', [BenchNoteController::class, 'index'])->name('bench-notes.index');
                Route::get('/bench-notes/create', [BenchNoteController::class, 'create'])->name('bench-notes.create');
                Route::post('/bench-notes', [BenchNoteController::class, 'store'])->name('bench-notes.store');
                Route::get('/bench-notes/{benchNote}', [BenchNoteController::class, 'show'])->name('bench-notes.show');
                Route::get('/bench-notes/{benchNote}/edit', [BenchNoteController::class, 'edit'])->name('bench-notes.edit');
                Route::patch('/bench-notes/{benchNote}', [BenchNoteController::class, 'update'])->name('bench-notes.update');
                Route::delete('/bench-notes/{benchNote}', [BenchNoteController::class, 'destroy'])->name('bench-notes.destroy');
            });

            // Applicants
            // The court's register of lawyers (Modules\Court\Http\Controllers\Admin\LawyerController).
            Route::get('/lawyers', [LawyerController::class, 'index'])->middleware('court.perm:applicants.view')->name('lawyers.index');
            Route::post('/lawyers', [LawyerController::class, 'store'])->middleware(['court.perm:applicants.manage', 'throttle:20,1'])->name('lawyers.store');
            Route::patch('/lawyers/{applicant}/verification', [LawyerController::class, 'verify'])->middleware(['court.perm:applicants.manage', 'throttle:30,1'])->name('lawyers.verify');
            Route::patch('/lawyers/{applicant}/refusal', [LawyerController::class, 'refuse'])->middleware(['court.perm:applicants.manage', 'throttle:30,1'])->name('lawyers.refuse');
            Route::get('/lawyers/{applicant}', [LawyerController::class, 'show'])->middleware('court.perm:applicants.view')->whereNumber('applicant')->name('lawyers.show');
            Route::get('/lawyers/{applicant}/license',[LawyerController::class, 'license'])->middleware('court.perm:applicants.view')->name('lawyers.license');
            Route::get('/applicants', [ApplicantController::class, 'index'])
                ->middleware('court.perm:applicants.view')
                ->name('applicants.index');
            Route::patch('/applicants/{applicant}/status', [ApplicantController::class, 'updateStatus'])
                ->middleware('court.perm:applicants.manage')
                ->name('applicants.status.update');

            // Teams
            Route::get('/teams', [TeamController::class, 'index'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.index');
            Route::get('/teams/create', [TeamController::class, 'create'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.create');
            Route::post('/teams', [TeamController::class, 'store'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.store');
            Route::get('/teams/{team}', [TeamController::class, 'show'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.show');
            Route::get('/teams/{team}/edit', [TeamController::class, 'edit'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.edit');
            Route::patch('/teams/{team}', [TeamController::class, 'update'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.update');
            Route::patch('/teams/{team}/users', [TeamController::class, 'updateUsers'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.users.update');
            Route::delete('/teams/{team}', [TeamController::class, 'destroy'])
                ->middleware('court.perm:teams.manage')
                ->name('teams.destroy');

            /*
             * Permissions (admin)
             * (kept inside /admin; names remain 'permissions.*' which matches the sidebar)
             */
            Route::resource('permissions', PermissionController::class)
                ->except(['show'])
                ->middleware('court.perm:permissions.manage');

            /*
             * Appeals (admin)
             */
            Route::get('/appeals', [AppealController::class, 'index'])->middleware('court.perm:appeals.view')->name('appeals.index');
            Route::get('/appeals/create', [AppealController::class, 'create'])->middleware('court.perm:appeals.create')->name('appeals.create');
            Route::post('/appeals', [AppealController::class, 'store'])->middleware('court.perm:appeals.create')->name('appeals.store');
            Route::get('/appeals/{appeal}', [AppealController::class, 'show'])->middleware('court.perm:appeals.view')->name('appeals.show');
            Route::get('/appeals/{appeal}/edit', [AppealController::class, 'edit'])->middleware('court.perm:appeals.edit')->name('appeals.edit');
            Route::patch('/appeals/{appeal}', [AppealController::class, 'update'])->middleware('court.perm:appeals.edit')->name('appeals.update');
            Route::post('/appeals/{appeal}/submit', [AppealController::class, 'submit'])->middleware('court.perm:appeals.edit')->name('appeals.submit');
            Route::post('/appeals/{appeal}/decide', [AppealController::class, 'decide'])->middleware('court.perm:appeals.decide')->name('appeals.decide');
            // The appellate court's outcome on a released record (EUISIS ADR 0013 #14).
            Route::post('/appeals/{appeal}/outcome', [AppealController::class, 'outcome'])->middleware(['court.perm:appeals.decide', 'throttle:20,1'])->name('appeals.outcome');
            Route::get('/appeals/{appeal}/outcome-document', [AppealController::class, 'outcomeDocument'])->middleware('court.perm:appeals.view')->name('appeals.outcome-document');

            /*
             * Decisions (admin)
             */
            Route::get('/decisions', [DecisionController::class, 'index'])
                ->middleware('court.perm:decision.view')
                ->name('decisions.index');
            Route::get('/decisions/create', [DecisionController::class, 'create'])
                ->middleware('court.perm:decision.create')
                ->name('decisions.create');
            Route::post('/decisions', [DecisionController::class, 'store'])
                ->middleware('court.perm:decision.create')
                ->name('decisions.store');
            Route::get('/decisions/{decision}', [DecisionController::class, 'show'])
                ->middleware('court.perm:decision.view')
                ->name('decisions.show');
            Route::get('/decisions/{decision}/output', [DecisionController::class, 'output'])
                ->middleware('court.perm:decision.view')
                ->name('decisions.output');
            Route::patch('/decisions/{decision}/status', [DecisionController::class, 'updateStatus'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.status');
            Route::patch('/decisions/{decision}/approve', [DecisionController::class, 'approve'])
                ->middleware('court.perm:decision.approve')
                ->name('decisions.approve');
            Route::post('/decisions/{decision}/reviews', [DecisionController::class, 'storeReview'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.reviews.store');
            Route::get('/decisions/{decision}/reviews/{review}/edit', [DecisionController::class, 'editReview'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.reviews.edit');
            Route::patch('/decisions/{decision}/reviews/{review}', [DecisionController::class, 'updateReview'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.reviews.update');
            Route::delete('/decisions/{decision}/reviews/{review}', [DecisionController::class, 'destroyReview'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.reviews.destroy');
            Route::get('/decisions/{decision}/edit', [DecisionController::class, 'edit'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.edit');
            Route::patch('/decisions/{decision}', [DecisionController::class, 'update'])
                ->middleware('court.perm:decision.update')
                ->name('decisions.update');
            Route::delete('/decisions/{decision}', [DecisionController::class, 'destroy'])
                ->middleware('court.perm:decision.delete')
                ->name('decisions.destroy');

            // Case records (consolidated PDF/HTML)
            Route::get('/recordes', [RecordController::class, 'index'])
                ->middleware('court.perm:cases.view')
                ->name('recordes.index');
            Route::get('/recordes/{case}', [RecordController::class, 'show'])
                ->middleware('court.perm:cases.view')
                ->name('recordes.show');
            Route::get('/recordes/{case}/pdf', [RecordController::class, 'pdf'])
                ->middleware('court.perm:cases.view')
                ->name('recordes.pdf');
            Route::get('/recordes/{case}/appeal-pdf', [RecordController::class, 'appealPdf'])
                ->middleware('court.perm:cases.view')
                ->name('recordes.appeal-pdf');

            // Appeal documents
            Route::post('/appeals/{appeal}/documents', [AppealController::class, 'uploadDoc'])->middleware('court.perm:appeals.edit')->name('appeals.docs.upload');
            Route::get('/appeals/{appeal}/documents/{doc}/download', [SecureFileController::class, 'appealDocument'])
                ->middleware('court.perm:appeals.view')
                ->name('appeals.docs.download');
            Route::delete('/appeals/{appeal}/documents/{doc}', [AppealController::class, 'deleteDoc'])->middleware('court.perm:appeals.edit')->name('appeals.docs.delete');

            // Performance Evaluations
            Route::get('/performance-evaluations', [PerformanceEvaluationController::class, 'index'])
                ->middleware('court.perm:performance-evaluations.view')->name('performance-evaluations.index');
            Route::get('/performance-evaluations/create', [PerformanceEvaluationController::class, 'create'])
                ->middleware('court.perm:performance-evaluations.create')->name('performance-evaluations.create');
            Route::get('/performance-evaluations/appeal-record', [PerformanceEvaluationController::class, 'appealRecord'])
                ->middleware('court.perm:performance-evaluations.create')->name('performance-evaluations.appeal-record');
            Route::post('/performance-evaluations', [PerformanceEvaluationController::class, 'store'])
                ->middleware('court.perm:performance-evaluations.create')->name('performance-evaluations.store');
            Route::get('/performance-evaluations/{performanceEvaluation}', [PerformanceEvaluationController::class, 'show'])
                ->middleware('court.perm:performance-evaluations.view')->name('performance-evaluations.show');
            Route::get('/performance-evaluations/{performanceEvaluation}/edit', [PerformanceEvaluationController::class, 'edit'])
                ->middleware('court.perm:performance-evaluations.update')->name('performance-evaluations.edit');
            Route::patch('/performance-evaluations/{performanceEvaluation}', [PerformanceEvaluationController::class, 'update'])
                ->middleware('court.perm:performance-evaluations.update')->name('performance-evaluations.update');
            Route::delete('/performance-evaluations/{performanceEvaluation}', [PerformanceEvaluationController::class, 'destroy'])
                ->middleware('court.perm:performance-evaluations.delete')->name('performance-evaluations.destroy');
            Route::post('/performance-evaluations/{performanceEvaluation}/review', [PerformanceEvaluationController::class, 'review'])
                ->middleware('court.perm:performance-evaluations.review')->name('performance-evaluations.review');

            // Admin notifications (matches admin layout links)
            Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('admin.notifications.index');
            Route::get('/notifications/count', [AdminNotificationController::class, 'count'])->name('admin.notifications.count');
            Route::post('/notifications/mark-one', [AdminNotificationController::class, 'markOne'])->name('admin.notifications.markOne');
            Route::post('/notifications/mark-all', [AdminNotificationController::class, 'markAll'])->name('admin.notifications.markAll');
            // The header bell of the React screens (route names the EUISIS NotificationBell uses)
            Route::get('/notifications/feed', [NotificationFeedController::class, 'feed'])->name('notifications.feed');
            Route::post('/notifications/read-all', [NotificationFeedController::class, 'readAll'])->name('notifications.read-all');
            Route::post('/notifications/{notice}/read', [NotificationFeedController::class, 'read'])->name('notifications.read');

            // Case Types (matches admin layout link 'case-types.index')
            Route::get('/case-types', [CaseTypeController::class, 'index'])->name('case-types.index');
            Route::get('/case-types/create', [CaseTypeController::class, 'create'])->name('case-types.create');
            Route::post('/case-types', [CaseTypeController::class, 'store'])->name('case-types.store');
            Route::get('/case-types/{id}/edit', [CaseTypeController::class, 'edit'])->name('case-types.edit');
            Route::patch('/case-types/{id}', [CaseTypeController::class, 'update'])->name('case-types.update');
            Route::delete('/case-types/{id}', [CaseTypeController::class, 'destroy'])->name('case-types.delete');

            // Letter templates
            // Letter templates with granular permissions
            Route::get('/letter-templates', [LetterTemplateController::class, 'index'])
                ->middleware('court.perm:letters.templet.view')
                ->name('letter-templates.index');
            Route::get('/letter-templates/create', [LetterTemplateController::class, 'create'])
                ->middleware('court.perm:letters.templet.create')
                ->name('letter-templates.create');
            Route::post('/letter-templates', [LetterTemplateController::class, 'store'])
                ->middleware('court.perm:letters.templet.create')
                ->name('letter-templates.store');
            Route::get('/letter-templates/{letter_template}/edit', [LetterTemplateController::class, 'edit'])
                ->middleware('court.perm:letters.templet.update')
                ->name('letter-templates.edit');
            Route::patch('/letter-templates/{letter_template}', [LetterTemplateController::class, 'update'])
                ->middleware('court.perm:letters.templet.update')
                ->name('letter-templates.update');
            Route::delete('/letter-templates/{letter_template}', [LetterTemplateController::class, 'destroy'])
                ->middleware('court.perm:letters.templet.delete')
                ->name('letter-templates.destroy');

            Route::resource('terms', TermsAndConditionsController::class)
                ->except(['show'])
                ->middleware('court.perm:settings.manage')
                ->names('terms');

            Route::resource('about', AboutController::class)
                ->middleware('court.perm:about.manage')
                ->names('about');

            Route::get('/case-inspections', function (Request $request) {
                $user = $request->user();
                abort_unless(
                    $user && (
                        $user->hasPermission('inspection-requests.manage')
                        || $user->hasPermission('inspection-findings.manage')
                    ),
                    403
                );

                if ($user->hasPermission('inspection-requests.manage')) {
                    return redirect()->route('court.case-inspection-requests.index');
                }

                return redirect()->route('court.case-inspection-findings.index');
            })
                ->name('case-inspections.index');
            Route::prefix('/case-inspections/requests')->name('case-inspection-requests.')->group(function () {
                Route::get('/', [CaseInspectionRequestController::class, 'index'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('index');
                Route::get('/create', [CaseInspectionRequestController::class, 'create'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('create');
                Route::post('/', [CaseInspectionRequestController::class, 'store'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('store');
                Route::get('/{requestRecord}', [CaseInspectionRequestController::class, 'show'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('show');
                Route::get('/{requestRecord}/edit', [CaseInspectionRequestController::class, 'edit'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('edit');
                Route::patch('/{requestRecord}', [CaseInspectionRequestController::class, 'update'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('update');
                Route::delete('/{requestRecord}', [CaseInspectionRequestController::class, 'destroy'])
                    ->middleware('court.perm:inspection-requests.manage')
                    ->name('destroy');
            });

            Route::prefix('/case-inspections/findings')->name('case-inspection-findings.')->group(function () {
                Route::get('/', [CaseInspectionFindingController::class, 'index'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('index');
                Route::get('/create', [CaseInspectionFindingController::class, 'create'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('create');
                Route::post('/', [CaseInspectionFindingController::class, 'store'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('store');
                Route::get('/{finding}', [CaseInspectionFindingController::class, 'show'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('show');
                Route::get('/{finding}/edit', [CaseInspectionFindingController::class, 'edit'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('edit');
                Route::patch('/{finding}', [CaseInspectionFindingController::class, 'update'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('update');
                Route::post('/{finding}/accept', [CaseInspectionFindingController::class, 'accept'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('accept');
                Route::get('/{finding}/attachment', [CaseInspectionFindingController::class, 'downloadAttachment'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('attachment');
                Route::delete('/{finding}', [CaseInspectionFindingController::class, 'destroy'])
                    ->middleware('court.perm:inspection-findings.manage')
                    ->name('destroy');
            });

            // Letter categories
            Route::get('/letter-categories', [LetterCategoryController::class, 'index'])
                ->middleware('court.perm:letters.templet.view')
                ->name('letter-categories.index');
            Route::get('/letter-categories/create', [LetterCategoryController::class, 'create'])
                ->middleware('court.perm:letters.templet.create')
                ->name('letter-categories.create');
            Route::post('/letter-categories', [LetterCategoryController::class, 'store'])
                ->middleware('court.perm:letters.templet.create')
                ->name('letter-categories.store');
            Route::get('/letter-categories/{letterCategory}/edit', [LetterCategoryController::class, 'edit'])
                ->middleware('court.perm:letters.templet.update')
                ->name('letter-categories.edit');
            Route::patch('/letter-categories/{letterCategory}', [LetterCategoryController::class, 'update'])
                ->middleware('court.perm:letters.templet.update')
                ->name('letter-categories.update');
            Route::delete('/letter-categories/{letterCategory}', [LetterCategoryController::class, 'destroy'])
                ->middleware('court.perm:letters.templet.delete')
                ->name('letter-categories.destroy');

            // Decision templates with granular permissions
            Route::get('/decision-templates', [DecisionTemplateController::class, 'index'])
                ->middleware('court.perm:decision.templet.view')
                ->name('decision-templates.index');
            Route::get('/decision-templates/create', [DecisionTemplateController::class, 'create'])
                ->middleware('court.perm:decision.templet.create')
                ->name('decision-templates.create');
            Route::post('/decision-templates', [DecisionTemplateController::class, 'store'])
                ->middleware('court.perm:decision.templet.create')
                ->name('decision-templates.store');
            Route::get('/decision-templates/{decisionTemplate}/edit', [DecisionTemplateController::class, 'edit'])
                ->middleware('court.perm:decision.templet.update')
                ->name('decision-templates.edit');
            Route::patch('/decision-templates/{decisionTemplate}', [DecisionTemplateController::class, 'update'])
                ->middleware('court.perm:decision.templet.update')
                ->name('decision-templates.update');
            Route::delete('/decision-templates/{decisionTemplate}', [DecisionTemplateController::class, 'destroy'])
                ->middleware('court.perm:decision.templet.delete')
                ->name('decision-templates.destroy');

            // Letters with granular permissions
            Route::get('/letters/compose', [LetterComposerController::class, 'create'])
                ->middleware('court.perm:letters.create')
                ->name('letters.compose');
            Route::get('/letters', [LetterController::class, 'index'])
                ->middleware('court.perm:letters.view')
                ->name('letters.index');
            Route::post('/letters', [LetterController::class, 'store'])
                ->middleware('court.perm:letters.create')
                ->name('letters.store');
            Route::get('/letters/{letter}', [LetterController::class, 'show'])
                ->middleware('court.perm:letters.view')
                ->name('letters.show');
            Route::get('/letters/{letter}/edit', [LetterController::class, 'edit'])
                ->middleware('court.perm:letters.update')
                ->name('letters.edit');
            Route::post('/letters/{letter}/approve', [LetterController::class, 'approve'])
                ->middleware('court.perm:letters.approve')
                ->name('letters.approve');
            Route::patch('/letters/{letter}', [LetterController::class, 'update'])
                ->middleware('court.perm:letters.update')
                ->name('letters.update');
            Route::delete('/letters/{letter}', [LetterController::class, 'destroy'])
                ->middleware('court.perm:letters.delete')
                ->name('letters.destroy');

            Route::middleware('court.perm:announcements.manage')->group(function () {
                Route::resource('announcements', AnnouncementController::class)
                    ->names('announcements');
            });

        });

        /*
         * Users & Roles (top-level, still under auth:)
         * Keep names as 'users.*' and 'roles.*' to satisfy admin layout.
         */
        Route::middleware('court.perm:users.manage')->group(function () {
            Route::get('users', [UsersController::class, 'index'])->name('users.index');
            Route::get('users/create', [UsersController::class, 'create'])->name('users.create');
            Route::post('users', [UsersController::class, 'store'])->name('users.store');

            // Staff accounts and their EUISIS employees (docs/euisis-integration.md, "Staff accounts").
            Route::get('users/euisis-staff', [EuisisStaffController::class, 'index'])->name('users.euisis.index');
            Route::put('users/euisis-staff/units', [EuisisStaffController::class, 'units'])->middleware('throttle:20,1')->name('users.euisis.units');
            Route::post('users/{user}/euisis', [EuisisStaffController::class, 'link'])->middleware('throttle:20,1')->whereNumber('user')->name('users.euisis.link');
            Route::delete('users/{user}/euisis', [EuisisStaffController::class, 'unlink'])->whereNumber('user')->name('users.euisis.unlink');
        });

        Route::get('users/{user}', [UsersController::class, 'show'])->middleware('can:view,user')->name('users.show');
        Route::get('users/{user}/edit', [UsersController::class, 'edit'])->middleware('can:update,user')->name('users.edit');
        Route::patch('users/{user}', [UsersController::class, 'update'])->middleware('can:update,user')->name('users.update');
        Route::delete('users/{user}', [UsersController::class, 'destroy'])->middleware('can:delete,user')->name('users.destroy');

        // Roles (resource with explicit names 'roles.*' as used in sidebar)
        Route::resource('roles', RolesController::class)
            ->except(['show'])
            ->middleware('court.perm:roles.manage')
            ->names('roles');
    });

});
