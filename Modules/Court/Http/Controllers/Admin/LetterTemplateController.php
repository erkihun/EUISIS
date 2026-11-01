<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\LetterCategory;
use Modules\Court\Domain\Models\LetterTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Court\Application\Support\Purifier;

class LetterTemplateController extends Controller
{
    /** The register (React, Court/LetterTemplates/Index); the actions follow the Blade page's permissions. */
    public function index(Request $request): Response
    {
        $templates = LetterTemplate::query()
            ->orderBy('title')
            ->paginate(12)
            ->withQueryString();

        $user = $request->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);

        return Inertia::render('Court/LetterTemplates/Index', [
            'templates' => $templates->through(fn (LetterTemplate $template): array => [
                'id' => (int) $template->id,
                'title' => (string) $template->title,
                'category' => $template->category ?: null,
                'placeholders' => $this->placeholderList($template),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]),
            'can' => [
                'create' => $can('letters.templet.create'),
                'update' => $can('letters.templet.update'),
                'delete' => $can('letters.templet.delete'),
                'compose' => $can('letters.create'),
                'categories' => $can('letters.templet.view'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Court/LetterTemplates/Form', [
            'template' => null,
            'categories' => $this->categoryNames(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data = $this->handleUploads($request, new LetterTemplate(), $data);

        LetterTemplate::create($data);

        return redirect()->route('court.letter-templates.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.letter_template')],
        ]);
    }

    public function edit(LetterTemplate $letterTemplate): Response
    {
        $disk = Storage::disk('court_public');

        return Inertia::render('Court/LetterTemplates/Form', [
            'template' => [
                'id' => (int) $letterTemplate->id,
                'title' => (string) $letterTemplate->title,
                'category' => $letterTemplate->category ?: null,
                'placeholders' => implode(', ', $this->placeholderList($letterTemplate)),
                'body' => (string) ($letterTemplate->body ?? ''),
                'header_image_url' => $letterTemplate->header_image_path ? $disk->url($letterTemplate->header_image_path) : null,
                'header_image_name' => $letterTemplate->header_image_path ? basename($letterTemplate->header_image_path) : null,
                'footer_image_url' => $letterTemplate->footer_image_path ? $disk->url($letterTemplate->footer_image_path) : null,
                'footer_image_name' => $letterTemplate->footer_image_path ? basename($letterTemplate->footer_image_path) : null,
            ],
            'categories' => $this->categoryNames(),
        ]);
    }

    public function update(Request $request, LetterTemplate $letterTemplate)
    {
        $data = $this->validateTemplate($request);
        $data = $this->handleUploads($request, $letterTemplate, $data);

        $letterTemplate->update($data);

        return redirect()->route('court.letter-templates.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.letter_template')],
        ]);
    }

    public function destroy(LetterTemplate $letterTemplate)
    {
        $letterTemplate->delete();

        return redirect()->route('court.letter-templates.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.letter_template')],
        ]);
    }

    /** @return list<string> */
    private function categoryNames(): array
    {
        return LetterCategory::query()->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->values()->all();
    }

    /** @return list<string> */
    private function placeholderList(LetterTemplate $template): array
    {
        $placeholders = $template->placeholders ?? [];
        if (is_string($placeholders)) {
            $placeholders = json_decode($placeholders, true) ?: [];
        }

        return array_values(array_map('strval', is_array($placeholders) ? $placeholders : []));
    }

    private function validateTemplate(Request $request): array
    {
        $data = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'category'            => ['nullable', 'string', 'max:120'],
            'placeholders'        => ['nullable', 'string', 'max:2000'],
            'body'                => ['required', 'string'],
            'header_image'        => ['nullable', 'image', 'max:3072'],
            'footer_image'        => ['nullable', 'image', 'max:3072'],
            'remove_header_image' => ['nullable', 'boolean'],
            'remove_footer_image' => ['nullable', 'boolean'],
        ]);

        // Clean placeholders into array format
        $data['placeholders'] = $this->normalizePlaceholders($data['placeholders'] ?? null);

        // Sanitize the user-supplied HTML body before storing, with the profile a
        // letter written from it is saved with ({{ placeholder }} tokens are text).
        $data['body'] = \Modules\Court\Application\Support\Purifier::clean((string) $data['body'], 'default');

        return $data;
    }

    private function normalizePlaceholders(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parts = preg_split('/[\r\n,]+/', $raw);
        $parts = array_filter(array_map('trim', $parts));

        return empty($parts) ? null : array_values(array_unique($parts));
    }

    private function handleUploads(Request $request, LetterTemplate $template, array $data): array
    {
        // Remove HEADER
        if ($request->boolean('remove_header_image') && $template->header_image_path) {
            Storage::disk('court_public')->delete($template->header_image_path);
            $data['header_image_path'] = null;
        }

        // Remove FOOTER
        if ($request->boolean('remove_footer_image') && $template->footer_image_path) {
            Storage::disk('court_public')->delete($template->footer_image_path);
            $data['footer_image_path'] = null;
        }

        // Upload HEADER
        if ($request->hasFile('header_image')) {
            if ($template->header_image_path) {
                Storage::disk('court_public')->delete($template->header_image_path);
            }
            $data['header_image_path'] = $request->file('header_image')
                ->store('letter-templates/headers', 'court_public');
        }

        // Upload FOOTER
        if ($request->hasFile('footer_image')) {
            if ($template->footer_image_path) {
                Storage::disk('court_public')->delete($template->footer_image_path);
            }
            $data['footer_image_path'] = $request->file('footer_image')
                ->store('letter-templates/footers', 'court_public');
        }

        // Cleanup unused fields
        unset(
            $data['header_image'],
            $data['footer_image'],
            $data['remove_header_image'],
            $data['remove_footer_image']
        );

        return $data;
    }
}
