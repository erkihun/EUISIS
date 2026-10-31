<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\AboutPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AboutController extends Controller
{
    public function index()
    {
        $aboutPages = AboutPage::orderByDesc('updated_at')->paginate(10);

        // The list (React, Court/About/Index).
        return Inertia::render('Court/About/Index', [
            'pages' => $aboutPages->through(fn (AboutPage $page): array => [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'is_published' => (bool) $page->is_published,
                'updated_at' => $page->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create()
    {
        return Inertia::render('Court/About/Form', ['page' => null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'alpha_dash', 'unique:court_about_pages,slug'],
            'body' => ['required', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['title']);
        $slug = $this->uniqueSlug($slug);

        AboutPage::create([
            'title' => $data['title'],
            'slug' => $slug,
            'body' => $this->cleanBody($data['body']),
            'is_published' => (bool) ($data['is_published'] ?? false),
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.about.index')->with('success', __('court::about.saved'));
    }

    public function show(AboutPage $about)
    {
        return Inertia::render('Court/About/Show', ['page' => $this->pageProps($about)]);
    }

    public function edit(AboutPage $about)
    {
        return Inertia::render('Court/About/Form', ['page' => $this->pageProps($about)]);
    }

    /** @return array{id: int, title: string, slug: string, body: string, is_published: bool} */
    private function pageProps(AboutPage $about): array
    {
        return [
            'id' => (int) $about->id,
            'title' => (string) $about->title,
            'slug' => (string) $about->slug,
            // Purified on save; cleaned again for the page, which shows it as rich text.
            'body' => $this->cleanBody((string) $about->body),
            'is_published' => (bool) $about->is_published,
        ];
    }

    public function update(Request $request, AboutPage $about)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'alpha_dash', 'unique:court_about_pages,slug,' . $about->id],
            'body' => ['required', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['title']);
        $slug = $this->uniqueSlug($slug, $about->id);

        $about->update([
            'title' => $data['title'],
            'slug' => $slug,
            'body' => $this->cleanBody($data['body']),
            'is_published' => (bool) ($data['is_published'] ?? false),
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.about.index')->with('success', __('court::about.updated'));
    }

    public function destroy(AboutPage $about)
    {
        $about->delete();

        return redirect()->route('court.about.index')->with('success', __('court::about.deleted'));
    }

    /**
     * The body is shown as HTML on the applicant sign-in page, which purifies it
     * with the same profile on output (ApplicantAuthController); it is purified
     * before it is stored as well.
     */
    private function cleanBody(string $html): string
    {
        return trim((string) \Modules\Court\Application\Support\Purifier::clean($html, 'cases'));
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug === '' ? 'about' : $slug;
        $candidate = $base;
        $i = 2;

        while (
            AboutPage::query()
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base . '-' . $i;
            $i++;
        }

        return $candidate;
    }
}
