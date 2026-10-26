<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageEmployeeEvidence;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An employee's documents, photo and signature on the employee page
 * (ADR 0009): validate, authorize (EmployeeDocumentPolicy), act
 * (ManageEmployeeEvidence). The employee is resolved inside the reader's
 * scope, and a document that is not this employee's is not found.
 */
class EmployeeEvidenceController extends Controller
{
    public function __construct(private readonly ManageEmployeeEvidence $evidence) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(EmployeeDocument::KINDS)],
            'document_type' => ['required_if:kind,document', 'nullable', Rule::in(EmployeeDocument::DOCUMENT_TYPES)],
            'file' => ['required', 'file'],
            ...$this->metaRules(),
        ]);
        Gate::authorize('upload', [EmployeeDocument::class, $employee, $data['kind']]);
        $this->evidence->upload($employee, $data['kind'], $request->file('file'), $data, $request->user());

        return back()->with('flash', ['message' => __('employees.evidence_done.uploaded'), 'type' => 'success']);
    }

    public function replace(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongs($employee, $document);
        Gate::authorize('replace', $document);
        $data = $request->validate(['file' => ['required', 'file'], ...$this->metaRules()]);
        $this->evidence->replace($document, $request->file('file'), $data, $request->user());

        return back()->with('flash', ['message' => __('employees.evidence_done.replaced'), 'type' => 'success']);
    }

    public function decide(Request $request, Employee $employee, EmployeeDocument $document, string $decision): RedirectResponse
    {
        $this->belongs($employee, $document);
        Gate::authorize('verify', $document);
        $data = $request->validate(['notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']]);
        $this->evidence->decide($document, $decision === 'reject' ? EmployeeDocument::REJECTED : EmployeeDocument::VERIFIED, $data['notes'] ?? null, $request->user());

        return back()->with('flash', ['message' => __('employees.evidence_done.'.$decision), 'type' => 'success']);
    }

    public function archive(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongs($employee, $document);
        Gate::authorize('archive', $document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->evidence->archive($document, $data['reason'], $request->user());

        return back()->with('flash', ['message' => __('employees.evidence_done.archived'), 'type' => 'success']);
    }

    public function discard(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongs($employee, $document);
        Gate::authorize('archive', $document);
        $this->evidence->discard($document, $request->user());

        return back()->with('flash', ['message' => __('employees.evidence_done.discarded'), 'type' => 'success']);
    }

    public function file(Request $request, Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        $this->belongs($employee, $document);
        Gate::authorize('download', $document);
        // Images may open in the page; a PDF is always a download.
        $inline = $request->boolean('inline') && $document->kind !== EmployeeDocument::DOCUMENT && str_starts_with($document->mime_type, 'image/');

        return $this->evidence->stream($document, $request->user(), $inline);
    }

    /** @return array<string, list<mixed>> */
    private function metaRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today', 'after_or_equal:issued_at'],
        ];
    }

    /** Knowing a document's id is not enough: it must be this (in-scope) employee's. */
    private function belongs(Employee $employee, EmployeeDocument $document): void
    {
        abort_unless((string) $document->employee_id === (string) $employee->getKey(), 404);
    }
}
