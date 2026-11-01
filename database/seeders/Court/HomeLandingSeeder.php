<?php

namespace Database\Seeders\Court;

use Illuminate\Database\Seeder;
use Modules\Court\Domain\Models\HomeFaq;
use Modules\Court\Domain\Models\HomeResource;
use Modules\Court\Domain\Models\HomeService;
use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\HomeSlide;
use Modules\Court\Domain\Models\HomeTimelineStep;

/**
 * Seeds default landing-page content for a fresh install.
 *
 * Non-destructive by design: each collection is seeded only while its table is
 * empty, and each setting only while it is unset, so re-running `db:seed` never
 * replaces content an administrator has authored through the landing CMS.
 */
class HomeLandingSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSlides();
        $this->seedTimeline();
        $this->seedServices();
        $this->seedFaqs();
        $this->seedResources();
        $this->seedSectionSettings();
        $this->seedFooter();
    }

    // ── Hero Slides ───────────────────────────────────────────────────────────

    private function seedSlides(): void
    {
        if (HomeSlide::query()->exists()) {
            return;
        }

        $slides = [
            [
                'sort_order'      => 1,
                'is_active'       => true,
                'badge'           => 'Digital Justice Platform',
                'title'           => 'Fast, Fair & Transparent Administrative Dispute Resolution',
                'description'     => 'File your case online, track hearings in real-time, and receive decisions through our secure digital tribunal system.',
                'primary_label'   => 'File a Case',
                'primary_href'    => '/court/applicant/register',
                'secondary_label' => 'Track My Case',
                'secondary_href'  => '/court/applicant/login',
                'bg_style'        => 'blue',
                'bg_image'        => null,
            ],
            [
                'sort_order'      => 2,
                'is_active'       => true,
                'badge'           => 'Now Open',
                'title'           => 'Submit Downloadable Forms & Legal Documents Instantly',
                'description'     => 'Access official court forms, submit evidence, and manage all documentation from a single secure portal — no physical visits required.',
                'primary_label'   => 'View Resources',
                'primary_href'    => '/court/resources',
                'secondary_label' => 'Learn More',
                'secondary_href'  => '#process',
                'bg_style'        => 'orange',
                'bg_image'        => null,
            ],
            [
                'sort_order'      => 3,
                'is_active'       => true,
                'badge'           => 'Live Dashboard',
                'title'           => 'Real-Time Case Statistics & Hearing Schedules',
                'description'     => 'Monitor tribunal performance, upcoming hearings, and case resolution metrics through our live public dashboard.',
                'primary_label'   => 'View Statistics',
                'primary_href'    => '#statistics',
                'secondary_label' => 'Recent Cases',
                'secondary_href'  => '#cases',
                'bg_style'        => 'emerald',
                'bg_image'        => null,
            ],
        ];

        foreach ($slides as $slide) {
            HomeSlide::create($slide);
        }
    }

    // ── Timeline Steps ────────────────────────────────────────────────────────

    private function seedTimeline(): void
    {
        if (HomeTimelineStep::query()->exists()) {
            return;
        }

        $steps = [
            [
                'sort_order'  => 1,
                'is_active'   => true,
                'title'       => 'Case Initiation',
                'description' => 'Register an account and submit your dispute online with all required documents and supporting evidence.',
                'meta'        => 'Step 1',
                'duration'    => '1–2 days',
                'color'       => 'blue',
            ],
            [
                'sort_order'  => 2,
                'is_active'   => true,
                'title'       => 'Review & Assignment',
                'description' => 'A registrar reviews your submission for completeness and assigns the case to an appropriate panel or judge.',
                'meta'        => 'Step 2',
                'duration'    => '3–5 days',
                'color'       => 'orange',
            ],
            [
                'sort_order'  => 3,
                'is_active'   => true,
                'title'       => 'Evidence Submission',
                'description' => 'Both parties submit evidence, witness statements, and written arguments through the secure online portal.',
                'meta'        => 'Step 3',
                'duration'    => '7–14 days',
                'color'       => 'violet',
            ],
            [
                'sort_order'  => 4,
                'is_active'   => true,
                'title'       => 'Hearing & Adjudication',
                'description' => 'The tribunal holds scheduled hearings, either in-person or virtual, and deliberates on the submitted facts.',
                'meta'        => 'Step 4',
                'duration'    => '14–30 days',
                'color'       => 'amber',
            ],
            [
                'sort_order'  => 5,
                'is_active'   => true,
                'title'       => 'Decision & Implementation',
                'description' => 'A final written decision is issued and served to all parties. Implementation is monitored by the tribunal.',
                'meta'        => 'Step 5',
                'duration'    => '7–10 days',
                'color'       => 'emerald',
            ],
        ];

        foreach ($steps as $step) {
            HomeTimelineStep::create($step);
        }
    }

    // ── Service Cards ─────────────────────────────────────────────────────────

    private function seedServices(): void
    {
        if (HomeService::query()->exists()) {
            return;
        }

        $services = [
            [
                'sort_order'  => 1,
                'is_active'   => true,
                'title'       => 'Digital Case Filing',
                'description' => 'Submit new administrative disputes entirely online with document upload and auto-generated case numbers.',
                'meta'        => 'Core Service',
                'features'    => ['Online submission 24/7', 'Automatic case number generation', 'Document upload support', 'SMS & email confirmations'],
                'icon_type'   => 'document',
                'accent'      => 'blue',
            ],
            [
                'sort_order'  => 2,
                'is_active'   => true,
                'title'       => 'Hearing Management',
                'description' => 'Schedule, reschedule, and attend hearings. Download ICS calendar files and receive automated reminders.',
                'meta'        => 'Core Service',
                'features'    => ['Online scheduling', 'ICS calendar downloads', 'Automated reminders', 'Virtual hearing support'],
                'icon_type'   => 'calendar',
                'accent'      => 'orange',
            ],
            [
                'sort_order'  => 3,
                'is_active'   => true,
                'title'       => 'Evidence Repository',
                'description' => 'Securely upload, organise, and share evidence files and witness statements with tribunal panels.',
                'meta'        => 'Core Service',
                'features'    => ['Encrypted file storage', 'Controlled party access', 'Witness statement upload', 'Audit trail for all submissions'],
                'icon_type'   => 'lock',
                'accent'      => 'emerald',
            ],
            [
                'sort_order'  => 4,
                'is_active'   => true,
                'title'       => 'Case Tracking Portal',
                'description' => 'Monitor case status, hearing dates, and tribunal decisions from a real-time applicant dashboard.',
                'meta'        => 'Core Service',
                'features'    => ['Live status updates', 'Hearing timeline view', 'Decision download', 'Notification preferences'],
                'icon_type'   => 'chart',
                'accent'      => 'violet',
            ],
            [
                'sort_order'  => 5,
                'is_active'   => true,
                'title'       => 'Decision Database',
                'description' => 'Browse past tribunal decisions and precedents. Full-text search across all published rulings.',
                'meta'        => 'Core Service',
                'features'    => ['Published rulings archive', 'Full-text search', 'Categorised by case type', 'Downloadable PDF decisions'],
                'icon_type'   => 'database',
                'accent'      => 'amber',
            ],
            [
                'sort_order'  => 6,
                'is_active'   => true,
                'title'       => 'Online Dispute Resolution',
                'description' => 'Resolve disputes through structured online mediation before escalating to a full tribunal hearing.',
                'meta'        => 'Core Service',
                'features'    => ['Structured mediation process', 'Secure messaging between parties', 'Settlement agreement drafting', 'Faster resolution timeline'],
                'icon_type'   => 'chat',
                'accent'      => 'pink',
            ],
        ];

        foreach ($services as $service) {
            HomeService::create($service);
        }
    }

    // ── FAQ ───────────────────────────────────────────────────────────────────

    private function seedFaqs(): void
    {
        if (HomeFaq::query()->exists()) {
            return;
        }

        $faqs = [
            [
                'sort_order' => 1,
                'is_active'  => true,
                'question'   => 'Who can file a case with the Administrative Tribunal?',
                'answer'     => 'Any individual or organisation that has a dispute arising from an administrative decision made by a public authority may file a case. This includes decisions on employment, permits, licenses, contracts, and regulatory matters. You must register as an applicant to begin the filing process.',
            ],
            [
                'sort_order' => 2,
                'is_active'  => true,
                'question'   => 'How long does the case resolution process take?',
                'answer'     => 'The timeline varies by case complexity. Simple cases with clear documentation are typically resolved within 30–45 days. Complex disputes involving multiple hearings and extensive evidence may take 3–6 months. You can monitor your case status in real-time through the applicant portal.',
            ],
            [
                'sort_order' => 3,
                'is_active'  => true,
                'question'   => 'What documents do I need to file a case?',
                'answer'     => 'You will need: (1) A completed petition form describing your dispute and the relief sought; (2) A copy of the administrative decision you are challenging; (3) Any correspondence with the respondent authority; (4) Supporting evidence such as contracts, photographs, or official records. All documents can be uploaded in PDF, DOCX, or image format.',
            ],
            [
                'sort_order' => 4,
                'is_active'  => true,
                'question'   => 'Are there any fees for filing a case?',
                'answer'     => 'Filing fees vary by case type and the relief amount sought. A detailed fee schedule is available in the Resources section. Some categories of applicants, including individuals with demonstrated financial hardship, may be eligible for fee waivers. Contact the registrar\'s office for further information.',
            ],
            [
                'sort_order' => 5,
                'is_active'  => true,
                'question'   => 'Can I attend hearings virtually?',
                'answer'     => 'Yes. The tribunal supports virtual hearings through its integrated video conferencing system. When a hearing is scheduled, you will receive a secure link via email. Physical attendance remains available and may be required in certain circumstances at the discretion of the panel.',
            ],
            [
                'sort_order' => 6,
                'is_active'  => true,
                'question'   => 'How do I respond to a case filed against me?',
                'answer'     => 'If you are named as a respondent, you will receive a formal notice through your registered contact details. Log in to the respondent portal, where you can review the petition, upload your written response, and submit supporting evidence. All responses must be filed within the deadline stated in the notice.',
            ],
            [
                'sort_order' => 7,
                'is_active'  => true,
                'question'   => 'Can I appeal a tribunal decision?',
                'answer'     => 'Yes. Parties may appeal a decision to the appropriate appellate body within 30 days of receiving the written ruling. The appeal must state the specific grounds of challenge. Use the Appeals section in your dashboard to initiate the process and upload your appeal petition.',
            ],
            [
                'sort_order' => 8,
                'is_active'  => true,
                'question'   => 'Is my case information kept confidential?',
                'answer'     => 'All case data is stored securely and is accessible only to the parties involved and authorised tribunal staff. Hearings may be conducted in closed session upon request when sensitive information is involved. Final decisions are published in the decision database in anonymised form unless otherwise ordered by the panel.',
            ],
        ];

        foreach ($faqs as $faq) {
            HomeFaq::create($faq);
        }
    }

    // ── Resources ─────────────────────────────────────────────────────────────

    private function seedResources(): void
    {
        if (HomeResource::query()->exists()) {
            return;
        }

        $resources = [
            [
                'sort_order'   => 1,
                'is_active'    => true,
                'is_featured'  => true,
                'type'         => 'post',
                'title'        => 'Tribunal Adopts New Digital Hearing Procedures for 2026',
                'description'  => 'The Administrative Tribunal has officially adopted updated digital hearing procedures effective January 2026. Key changes include mandatory pre-hearing document exchange via the portal, expanded use of virtual hearings for routine procedural matters, and new guidelines for electronic evidence submission. All parties are encouraged to review the updated procedural rules available in the documents section.',
                'external_url' => null,
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subDays(5),
            ],
            [
                'sort_order'   => 2,
                'is_active'    => true,
                'is_featured'  => true,
                'type'         => 'form',
                'title'        => 'Case Petition Form (Form AT-001)',
                'description'  => 'The standard petition form for initiating a new administrative dispute case. Complete all sections including party details, grounds of dispute, relief sought, and attach supporting documents. Available in English and Amharic.',
                'external_url' => null,
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subDays(30),
            ],
            [
                'sort_order'   => 3,
                'is_active'    => true,
                'is_featured'  => false,
                'type'         => 'form',
                'title'        => 'Evidence Submission Form (Form AT-003)',
                'description'  => 'Use this form to formally register and describe evidence items submitted to the tribunal. Each item must be logged with a description, source, and relevance statement before upload.',
                'external_url' => null,
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subDays(30),
            ],
            [
                'sort_order'   => 4,
                'is_active'    => true,
                'is_featured'  => false,
                'type'         => 'document',
                'title'        => 'Procedural Rules of the Administrative Tribunal (2025 Edition)',
                'description'  => 'The complete procedural rules governing case filing, evidence submission, hearing conduct, and decision-making at the Administrative Tribunal. This edition incorporates all amendments made through December 2025.',
                'external_url' => null,
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subMonths(2),
            ],
            [
                'sort_order'   => 5,
                'is_active'    => true,
                'is_featured'  => false,
                'type'         => 'post',
                'title'        => 'Q1 2026 Case Statistics Report Published',
                'description'  => 'The tribunal\'s Q1 2026 statistics report is now available. Key highlights: 312 new cases filed, 287 cases resolved, average resolution time of 34 days, and a 91% party satisfaction rate. The report includes a breakdown by case type and hearing format.',
                'external_url' => null,
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subDays(10),
            ],
            [
                'sort_order'   => 6,
                'is_active'    => true,
                'is_featured'  => false,
                'type'         => 'link',
                'title'        => 'Ethiopian Federal Civil Service Commission — Official Portal',
                'description'  => 'Access the official Civil Service Commission portal for information on administrative regulations, employment rules, and government directives that may be relevant to your case.',
                'external_url' => 'https://www.fsc.gov.et',
                'file_path'    => null,
                'cover_image'  => null,
                'published_at' => now()->subMonths(3),
            ],
        ];

        foreach ($resources as $resource) {
            HomeResource::create($resource);
        }
    }

    // ── Section Settings ──────────────────────────────────────────────────────

    private function seedSectionSettings(): void
    {
        $sections = [
            'metrics' => [
                'visible'  => true,
                'title'    => 'Tribunal by the Numbers',
                'subtitle' => 'Live statistics reflecting the tribunal\'s active caseload, resolution performance, and upcoming hearing schedule.',
            ],
            'process' => [
                'visible'  => true,
                'title'    => 'How Your Case Is Processed',
                'subtitle' => 'A transparent, step-by-step overview of the journey from initial filing to final decision.',
            ],
            'services' => [
                'visible'  => true,
                'title'    => 'Core Services',
                'subtitle' => 'Everything you need to file, manage, and resolve your administrative dispute — all in one place.',
            ],
            'cases' => [
                'visible' => true,
            ],
            'resources' => [
                'visible'  => true,
                'title'    => 'Resources & Publications',
                'subtitle' => 'Official forms, procedural documents, and court updates to help you navigate the tribunal process.',
            ],
            'cta' => [
                'visible'         => true,
                'title'           => 'Ready to resolve your dispute?',
                'description'     => 'Join thousands of applicants who have received fair, transparent decisions through our digital tribunal platform.',
                'primary_label'   => 'File a Case',
                'primary_href'    => '/court/applicant/register',
                'secondary_label' => 'Sign In',
                'secondary_href'  => '/court/applicant/login',
            ],
        ];

        foreach ($sections as $key => $value) {
            $this->setJsonIfMissing("section.{$key}", $value);
        }
    }

    // ── Footer ────────────────────────────────────────────────────────────────

    private function seedFooter(): void
    {
        $this->setJsonIfMissing('footer.settings', [
            'description'      => 'The Public Servants\' Human Rights Defence & Administrative Tribunal Commission provides independent, impartial resolution of administrative disputes for individuals and organisations across Ethiopia.',
            'contact_phone'    => '+251 11 551 7700',
            'contact_email'    => 'info@pshrdbatc.gov.et',
            'contact_address'  => 'Arat Kilo, Addis Ababa, Ethiopia',
            'social_facebook'  => 'https://www.facebook.com',
            'social_twitter'   => '',
            'social_linkedin'  => '',
            'social_youtube'   => 'https://www.youtube.com',
            'social_telegram'  => 'https://t.me',
            'social_instagram' => '',
        ]);
    }

    private function setJsonIfMissing(string $key, array $value): void
    {
        if (HomeSetting::get($key) !== null) {
            return;
        }

        HomeSetting::setJson($key, $value);
    }
}
