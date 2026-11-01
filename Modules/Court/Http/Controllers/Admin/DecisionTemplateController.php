<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\DecisionTemplate;
use Modules\Court\Application\Support\SafeHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DecisionTemplateController extends Controller
{
    public function index()
    {
        $templates = DecisionTemplate::query()
            ->orderBy('title')
            ->paginate(12)
            ->withQueryString();

        $user = auth()->user();

        // The register (React, Court/DecisionTemplates/Index).
        return Inertia::render('Court/DecisionTemplates/Index', [
            'templates' => $templates->through(fn (DecisionTemplate $template): array => [
                'id' => (int) $template->id,
                'title' => $template->title,
                'category' => $template->category ?: null,
                'is_default' => (bool) $template->is_default,
                'placeholders' => $template->placeholders ? implode(', ', $template->placeholders) : null,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]),
            'can' => [
                'create' => $user?->hasPermission('decision.templet.create') ?? false,
                'update' => $user?->hasPermission('decision.templet.update') ?? false,
                'delete' => $user?->hasPermission('decision.templet.delete') ?? false,
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Court/DecisionTemplates/Create', ['template' => null]);
    }

    /** The form's view of one template (React, Court/DecisionTemplates/<Create|Edit>). */
    private function templateProps(DecisionTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'title' => $template->title ?? '',
            'category' => $template->category ?? '',
            'is_default' => (bool) $template->is_default,
            'placeholders' => $template->placeholders ? implode(', ', $template->placeholders) : '',
            'body' => $template->body ?? '',
            'header_image_name' => $template->header_image_path ? basename($template->header_image_path) : null,
            'header_image_url' => $template->header_image_path ? asset('storage/'.$template->header_image_path) : null,
            'footer_image_name' => $template->footer_image_path ? basename($template->footer_image_path) : null,
            'footer_image_url' => $template->footer_image_path ? asset('storage/'.$template->footer_image_path) : null,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data = $this->handleUploads($request, new DecisionTemplate(), $data);

        $template = DecisionTemplate::create($data);
        $this->syncDefault($template);

        return redirect()->route('court.decision-templates.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.decision_template')],
        ]);
    }

    public function edit(DecisionTemplate $decisionTemplate)
    {
        return Inertia::render('Court/DecisionTemplates/Edit', [
            'template' => $this->templateProps($decisionTemplate),
        ]);
    }

    public function update(Request $request, DecisionTemplate $decisionTemplate)
    {
        $data = $this->validateTemplate($request);
        $data = $this->handleUploads($request, $decisionTemplate, $data);

        $decisionTemplate->update($data);
        $this->syncDefault($decisionTemplate);

        return redirect()->route('court.decision-templates.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.decision_template')],
        ]);
    }

    public function destroy(DecisionTemplate $decisionTemplate)
    {
        if ($decisionTemplate->header_image_path) {
            Storage::disk('court_public')->delete($decisionTemplate->header_image_path);
        }
        if ($decisionTemplate->footer_image_path) {
            Storage::disk('court_public')->delete($decisionTemplate->footer_image_path);
        }

        $decisionTemplate->delete();

        return redirect()->route('court.decision-templates.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.decision_template')],
        ]);
    }

    /**
     * Ensure at most one template is marked as default.
     */
    private function syncDefault(DecisionTemplate $template): void
    {
        if ($template->is_default) {
            DecisionTemplate::where('id', '!=', $template->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    private function validateTemplate(Request $request): array
    {
        $data = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'category'            => ['nullable', 'string', 'max:120'],
            'is_default'          => ['nullable', 'boolean'],
            'placeholders'        => ['nullable', 'string', 'max:2000'],
            'body'                => ['required', 'string'],
            'header_image'        => ['nullable', 'image', 'max:3072'],
            'footer_image'        => ['nullable', 'image', 'max:3072'],
            'remove_header_image' => ['nullable', 'boolean'],
            'remove_footer_image' => ['nullable', 'boolean'],
        ]);

        $data['is_default'] = $request->boolean('is_default');

        // Sanitize user-supplied HTML body before storing.
        $data['body'] = SafeHtml::clean($data['body']);

        // Clean placeholders into array format
        $data['placeholders'] = $this->normalizePlaceholders($data['placeholders'] ?? null);

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

    private function handleUploads(Request $request, DecisionTemplate $template, array $data): array
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
                ->store('decision-templates/headers', 'court_public');
        }

        // Upload FOOTER
        if ($request->hasFile('footer_image')) {
            if ($template->footer_image_path) {
                Storage::disk('court_public')->delete($template->footer_image_path);
            }
            $data['footer_image_path'] = $request->file('footer_image')
                ->store('decision-templates/footers', 'court_public');
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
