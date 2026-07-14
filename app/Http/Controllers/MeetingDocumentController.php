<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\TeamDocument;
use App\Services\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingDocumentController extends Controller
{
    public function __construct(private readonly MeetingService $service) {}

    public function attach(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('actas.gestionar');

        $data = $request->validate([
            'team_document_id' => ['required', 'integer', 'exists:team_documents,id'],
        ]);

        $doc = TeamDocument::findOrFail($data['team_document_id']);
        abort_unless($doc->year_id === $meeting->year_id, 422);

        $this->service->attachDocument($meeting, $doc->id);

        return back()->with('success', 'Documento asociado.');
    }

    public function detach(Meeting $meeting, TeamDocument $document): RedirectResponse
    {
        Gate::authorize('actas.gestionar');

        $this->service->detachDocument($meeting, $document->id);

        return back()->with('success', 'Documento desvinculado.');
    }
}
