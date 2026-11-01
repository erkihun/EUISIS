<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Announcement;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::latest()->paginate(15);

        // The list (React, Court/Announcements/Index).
        return Inertia::render('Court/Announcements/Index', [
            'announcements' => $announcements->through(fn (Announcement $announcement): array => [
                'id' => (int) $announcement->id,
                'title' => (string) $announcement->title,
                'status' => (string) ($announcement->status ?? 'active'),
                'created_at' => $announcement->created_at?->toIso8601String(),
                'updated_at' => $announcement->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create()
    {
        return Inertia::render('Court/Announcements/Form', ['announcement' => null]);
    }

    public function store(Request $request)
    {
        Announcement::create($this->validated($request));

        return redirect()->route('court.announcements.index')->with('success', __('court::announcements.status_saved'));
    }

    public function show(Announcement $announcement)
    {
        return Inertia::render('Court/Announcements/Show', ['announcement' => [
            ...$this->formProps($announcement),
            // Purified on output too: an announcement saved before content was cleaned on save may hold anything.
            'content' => $this->cleanContent((string) $announcement->content),
            'created_at' => $announcement->created_at?->toIso8601String(),
        ]]);
    }

    public function edit(Announcement $announcement)
    {
        return Inertia::render('Court/Announcements/Form', ['announcement' => $this->formProps($announcement)]);
    }

    /** @return array{id: int, title: string, content: string, status: string} */
    private function formProps(Announcement $announcement): array
    {
        return [
            'id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'content' => (string) $announcement->content,
            'status' => (string) ($announcement->status ?? 'active'),
        ];
    }

    public function update(Request $request, Announcement $announcement)
    {
        $announcement->update($this->validated($request));

        return redirect()->route('court.announcements.index')->with('success', __('court::announcements.status_updated'));
    }

    /**
     * The form's fields, with the rich text purified before it is stored (the
     * public signage board and this page clean it again when they show it).
     *
     * @return array{title: string, content: string, status: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $data['content'] = $this->cleanContent($data['content']);

        return $data;
    }

    /** The profile the signage board shows announcements with (PublicSignageController). */
    private function cleanContent(string $html): string
    {
        return trim((string) \Modules\Court\Application\Support\Purifier::clean($html, 'default'));
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return redirect()->route('court.announcements.index')->with('success', __('court::announcements.status_deleted'));
    }
}
