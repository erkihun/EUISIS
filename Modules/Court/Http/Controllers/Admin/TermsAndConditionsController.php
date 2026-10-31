<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\TermsAndCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class TermsAndConditionsController extends Controller
{
    public function index(): Response
    {
        $terms = TermsAndCondition::orderByDesc('created_at')->paginate(10);

        // The list (React, Court/Terms/Index).
        return Inertia::render('Court/Terms/Index', [
            'terms' => $terms->through(fn (TermsAndCondition $term): array => [
                'id' => (int) $term->id,
                'title' => (string) $term->title,
                'excerpt' => Str::limit(strip_tags((string) $term->body), 120),
                'is_published' => (bool) $term->is_published,
                'published_at' => $term->published_at?->toIso8601String(),
                'updated_at' => $term->updated_at?->toIso8601String(),
            ]),
            'publicUrl' => Route::has('court.public.terms') ? route('court.public.terms') : null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Court/Terms/Form', ['term' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        $data['published_at'] = $data['is_published'] ? now() : null;
        $data['slug'] = Str::slug($data['title'] . '-' . Str::random(4));

        TermsAndCondition::create($data);

        return redirect()->route('court.terms.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.terms')],
        ]);
    }

    public function edit(TermsAndCondition $term): Response
    {
        return Inertia::render('Court/Terms/Form', ['term' => [
            'id' => (int) $term->id,
            'title' => (string) $term->title,
            'body' => (string) $term->body,
            'is_published' => (bool) $term->is_published,
        ]]);
    }

    public function update(Request $request, TermsAndCondition $term): RedirectResponse
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        if ($data['is_published'] && !$term->published_at) {
            $data['published_at'] = now();
        } elseif (!$data['is_published']) {
            $data['published_at'] = null;
        }

        $term->update($data);

        return redirect()->route('court.terms.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.terms')],
        ]);
    }

    public function destroy(TermsAndCondition $term): RedirectResponse
    {
        $term->delete();

        return redirect()->route('court.terms.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.terms')],
        ]);
    }
}
