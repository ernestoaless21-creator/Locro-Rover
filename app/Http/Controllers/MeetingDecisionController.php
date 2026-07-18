<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Services\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MeetingDecisionController extends Controller
{
    public function __construct(private readonly MeetingService $service) {}

    public function store(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('actas.gestionar');
        Gate::authorize('mutate', $meeting->year);

        $data = $this->validateDecision($request);
        $this->service->addDecision($meeting, $data);

        return back()->with('success', 'Punto agregado.');
    }

    public function update(Request $request, Meeting $meeting, MeetingDecision $decision): RedirectResponse
    {
        Gate::authorize('actas.gestionar');
        abort_unless($decision->meeting_id === $meeting->id, 404);
        Gate::authorize('mutate', $meeting->year);

        $data = $this->validateDecision($request);
        $this->service->updateDecision($decision, $data);

        return back()->with('success', 'Punto actualizado.');
    }

    public function destroy(Meeting $meeting, MeetingDecision $decision): RedirectResponse
    {
        Gate::authorize('actas.gestionar');
        abort_unless($decision->meeting_id === $meeting->id, 404);
        Gate::authorize('mutate', $meeting->year);

        $this->service->deleteDecision($decision);

        return back()->with('success', 'Punto eliminado.');
    }

    private function validateDecision(Request $request): array
    {
        return $request->validate([
            'text' => ['required', 'string'],
            'category' => ['required', Rule::in(array_keys(MeetingDecision::CATEGORIES))],
            'team' => ['nullable', Rule::in(MeetingDecision::TEAMS)],
        ]);
    }
}
