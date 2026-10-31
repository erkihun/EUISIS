<?php

namespace Tests\Feature\Court;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\ApplicantResponseReply;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Notifications\ApplicantResponseReplyReviewed;
use Modules\Court\Application\Notifications\ApplicantResponseReplySubmitted;
use Modules\Court\Application\Notifications\RespondentResponseReviewed;
use Modules\Court\Application\Notifications\RespondentResponseSubmitted;
use Modules\Court\Application\Services\ResponseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResponseNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_telegram_group_alert_on_new_case_created_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyApplicantCaseCreated($caseId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'New case submitted')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_localized_telegram_group_alert_when_locale_is_amharic(): void
    {
        // Telegram group alerts follow the admin-configured system locale, not the
        // requesting user's app()->getLocale() (the alert goes to a shared group chat).
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications(locale: 'am');

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyApplicantCaseCreated($caseId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'አዲስ መዝገብ ለተቋሙ ቀርቧል')
            && str_contains((string) $request['text'], 'መዝገብ:')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_applicant_case_updated_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyApplicantCaseUpdated($caseId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Applicant updated case submission')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_case_status_changed_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyCaseStatusChanged($caseId, 'pending', 'active', 'Ready for hearing');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Case status changed')
            && str_contains((string) $request['text'], 'Ready for hearing')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_case_message_posted_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyCaseMessagePosted($caseId, 'Applicant', 'Please review my case message.');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'New case message posted')
            && str_contains((string) $request['text'], 'Applicant')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_case_hearing_created_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();
        $hearingId = DB::table('court_case_hearings')->insertGetId([
            'case_id' => $caseId,
            'hearing_at' => now()->addDays(7)->toDateTimeString(),
            'location' => 'Courtroom 1',
            'type' => 'Preliminary',
            'notes' => 'Bring originals',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ResponseNotificationService::notifyCaseHearingCreated($hearingId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Case hearing scheduled')
            && str_contains((string) $request['text'], 'Courtroom 1')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_respondent_case_viewed_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyRespondentCaseViewed($caseId, 'Respondent User');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Respondent viewed case')
            && str_contains((string) $request['text'], 'Respondent User')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_sends_telegram_group_alert_on_case_review_decision_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber] = $this->seedCaseWithParties();

        ResponseNotificationService::notifyCaseReviewDecision($caseId, 'accepted', 'Review complete');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Case review decision recorded')
            && str_contains((string) $request['text'], 'accepted')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_notifies_applicant_and_admin_on_respondent_response_created(): void
    {
        Notification::fake();

        [$admin, $caseId, $caseNumber, $applicant, $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'መ/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        ResponseNotificationService::notifyRespondentResponseCreated($response);

        Notification::assertSentTo($admin, RespondentResponseSubmitted::class);
        Notification::assertSentTo($applicant, RespondentResponseSubmitted::class);
    }

    public function test_sends_telegram_group_alert_on_respondent_response_created_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, , $caseNumber, , $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'R/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        ResponseNotificationService::notifyRespondentResponseCreated($response);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'New respondent response submitted')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_notifies_admin_and_respondent_on_response_reply_created(): void
    {
        Notification::fake();

        [$admin, $caseId, $caseNumber, $applicant, $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'መ/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        $reply = ApplicantResponseReply::create([
            'case_id' => $caseId,
            'applicant_id' => $applicant->id,
            'respondent_response_id' => $response->id,
            'description' => 'Reply body',
            'pdf_path' => 'applicant/response-replies/test.pdf',
            'review_status' => 'awaiting_review',
        ]);

        ResponseNotificationService::notifyApplicantResponseReplyCreated($reply, $response);

        Notification::assertSentTo($admin, ApplicantResponseReplySubmitted::class);
        Notification::assertSentTo($respondent, ApplicantResponseReplySubmitted::class);
    }

    public function test_sends_telegram_group_alert_on_applicant_response_reply_created_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber, $applicant, $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'R/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        $reply = ApplicantResponseReply::create([
            'case_id' => $caseId,
            'applicant_id' => $applicant->id,
            'respondent_response_id' => $response->id,
            'description' => 'Reply body',
            'pdf_path' => 'applicant/response-replies/test.pdf',
            'review_status' => 'awaiting_review',
        ]);

        ResponseNotificationService::notifyApplicantResponseReplyCreated($reply, $response);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'New applicant reply submitted')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_notifies_respondent_on_response_review(): void
    {
        Notification::fake();

        [, , $caseNumber, , $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'መ/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        ResponseNotificationService::notifyRespondentResponseReviewed($response, 'accepted', 'ok');

        Notification::assertSentTo($respondent, RespondentResponseReviewed::class);
    }

    public function test_sends_telegram_group_alert_on_respondent_response_review_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, , $caseNumber, , $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'R/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        ResponseNotificationService::notifyRespondentResponseReviewed($response, 'accepted', 'ok');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Respondent response reviewed')
            && str_contains((string) $request['text'], 'accepted')
            && str_contains((string) $request['text'], $caseNumber));
    }

    public function test_notifies_applicant_on_response_reply_review(): void
    {
        Notification::fake();

        [, $caseId, $caseNumber, $applicant, $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'መ/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        $reply = ApplicantResponseReply::create([
            'case_id' => $caseId,
            'applicant_id' => $applicant->id,
            'respondent_response_id' => $response->id,
            'description' => 'Reply body',
            'pdf_path' => 'applicant/response-replies/test.pdf',
            'review_status' => 'awaiting_review',
        ]);

        ResponseNotificationService::notifyResponseReplyReviewed($reply, 'returned', 'fix');

        Notification::assertSentTo($applicant, ApplicantResponseReplyReviewed::class);
    }

    public function test_sends_telegram_group_alert_on_response_reply_review_when_enabled(): void
    {
        Notification::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->enableTelegramNotifications();

        [, $caseId, $caseNumber, $applicant, $respondent] = $this->seedCaseWithParties();

        $response = RespondentResponse::create([
            'respondent_id' => $respondent->id,
            'case_number' => $caseNumber,
            'response_number' => 'R/'.$caseNumber,
            'title' => 'Test response',
            'description' => 'Body',
            'pdf_path' => 'respondent/responses/test.pdf',
        ]);

        $reply = ApplicantResponseReply::create([
            'case_id' => $caseId,
            'applicant_id' => $applicant->id,
            'respondent_response_id' => $response->id,
            'description' => 'Reply body',
            'pdf_path' => 'applicant/response-replies/test.pdf',
            'review_status' => 'awaiting_review',
        ]);

        ResponseNotificationService::notifyResponseReplyReviewed($reply, 'returned', 'fix');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123456789'
            && str_contains((string) $request['text'], 'Applicant reply reviewed')
            && str_contains((string) $request['text'], 'returned')
            && str_contains((string) $request['text'], $caseNumber));
    }

    /**
     * Seed a minimal case with parties and an admin reviewer.
     *
     * @return array{User,int,string,Applicant,Respondent}
     */
    private function seedCaseWithParties(): array
    {
        $admin = User::factory()->create();
        $roleId = DB::table('court_roles')->insertGetId([
            'name' => 'admin',
            'description' => 'Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('court_role_user')->insert([
            'user_id' => $admin->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caseTypeId = DB::table('court_case_types')->insertGetId([
            'name' => 'Civil',
            'description' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $applicant = Applicant::create([
            'first_name' => 'App',
            'middle_name' => 'M',
            'last_name' => 'User',
            'gender' => 'male',
            'phone' => '2519'.rand(10000000, 99999999),
            'email' => 'applicant'.rand(10, 9999).'@example.com',
            'password' => bcrypt('password'),
            'national_id_number' => (string) rand(1000000000000000, 9999999999999999),
            'address' => 'Address',
        ]);

        $respondent = Respondent::create([
            'first_name' => 'Resp',
            'middle_name' => 'R',
            'last_name' => 'User',
            'gender' => 'male',
            'position' => '',
            'organization_name' => '',
            'address' => 'Address',
            'national_id' => 'RN'.rand(1000, 9999),
            'phone' => '2517'.rand(10000000, 99999999),
            'email' => 'respondent'.rand(10, 9999).'@example.com',
            'password' => bcrypt('password'),
        ]);

        $caseNumber = 'CASE-'.rand(1000, 9999);
        $caseId = DB::table('court_cases')->insertGetId([
            'applicant_id' => $applicant->id,
            'case_number' => $caseNumber,
            'code' => null,
            'title' => 'Test case',
            'respondent_name' => $respondent->first_name,
            'respondent_address' => $respondent->address,
            'description' => 'Desc',
            'relief_requested' => null,
            'case_type_id' => $caseTypeId,
            'filing_date' => now()->toDateString(),
            'status' => 'pending',
            'assigned_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin, $caseId, $caseNumber, $applicant, $respondent];
    }

    private function enableTelegramNotifications(?string $locale = null): void
    {
        SystemSetting::create(array_filter([
            'app_name' => 'Court MS',
            'telegram_enabled' => true,
            'telegram_bot_token' => 'telegram-token',
            'telegram_default_chat_id' => '-100123456789',
            'default_locale' => $locale,
        ], fn ($value) => $value !== null));
    }
}
