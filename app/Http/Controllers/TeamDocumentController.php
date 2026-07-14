<?php

namespace App\Http\Controllers;

use App\Models\TeamDocument;
use App\Models\Year;
use App\Services\TeamDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamDocumentController extends Controller
{
    public function __construct(private readonly TeamDocumentService $documentService) {}

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'file'        => ['required', 'file', 'max:10240'], // 10 MB
            'year_id'     => ['nullable', 'integer', 'exists:years,id'],
        ]);

        $year = isset($data['year_id'])
            ? Year::findOrFail($data['year_id'])
            : Year::where('is_active', true)->firstOrFail();

        $this->documentService->store(
            file:        $request->file('file'),
            team:        $team,
            yearId:      $year->id,
            name:        $data['name'],
            description: $data['description'] ?? null,
            uploadedBy:  $request->user()->id,
        );

        return back()->with('success', 'Documento subido.');
    }

    public function update(Request $request, string $team, TeamDocument $doc): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($doc->team === $team, 404);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->documentService->update($doc, $data['name'], $data['description'] ?? null);

        return back()->with('success', 'Documento actualizado.');
    }

    public function download(Request $request, string $team, TeamDocument $doc): StreamedResponse
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($doc->team === $team, 404);

        return $this->documentService->download($doc);
    }

    public function destroy(Request $request, string $team, TeamDocument $doc): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($doc->team === $team, 404);

        $this->documentService->delete($doc);

        return back()->with('success', 'Documento eliminado.');
    }

    private function authorizeTeamAccess(Request $request, string $team): void
    {
        $user = $request->user();
        if ($user->can('equipos.gestionar-todos')) {
            return;
        }
        abort_unless($user->teamSlug() === $team, 403);
    }
}
