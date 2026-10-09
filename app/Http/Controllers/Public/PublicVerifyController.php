<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteSectionRegistry as Registry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entry point for verifying an ID card: scan a QR or type a reference.
 *
 * Only the page's WORDING comes from Public Site Management. Where a scanned
 * value goes, OTP issuance, limits and the fields revealed after verification
 * are all enforced by PublicIdCheckerService and cannot be changed here.
 */
class PublicVerifyController extends Controller
{
    public function index(PublicSiteContent $content): Response
    {
        return Inertia::render('Public/Verify', [
            'sections' => $content->sections(Registry::PAGE_VERIFY),
            'meta' => $content->meta(Registry::PAGE_VERIFY),
        ]);
    }
}
