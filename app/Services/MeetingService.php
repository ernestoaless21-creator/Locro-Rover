<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingDecision;
use App\Models\TeamDocument;
use App\Models\User;

class MeetingService
{
    public function create(array $data, int $yearId, int $createdBy): Meeting
    {
        $secretaryName = $this->resolveSecretaryName($data['secretary_id'] ?? null);

        $meeting = Meeting::create([
            'year_id'          => $yearId,
            'title'            => $data['title'],
            'date'             => $data['date'],
            'development'      => $data['development'] ?? null,
            'secretary_id'     => $data['secretary_id'] ?? null,
            'secretary_name'   => $secretaryName,
            'otros_asistentes' => $data['otros_asistentes'] ?? null,
            'created_by'       => $createdBy,
        ]);

        $this->syncAttendances($meeting, $data['attendances'] ?? []);

        return $meeting;
    }

    public function update(Meeting $meeting, array $data): void
    {
        $secretaryName = $this->resolveSecretaryName($data['secretary_id'] ?? null);

        $meeting->update([
            'title'            => $data['title'],
            'date'             => $data['date'],
            'development'      => $data['development'] ?? null,
            'secretary_id'     => $data['secretary_id'] ?? null,
            'secretary_name'   => $secretaryName,
            'otros_asistentes' => $data['otros_asistentes'] ?? null,
        ]);

        if (array_key_exists('attendances', $data)) {
            $this->syncAttendances($meeting, $data['attendances'] ?? []);
        }
    }

    public function delete(Meeting $meeting): void
    {
        $meeting->delete();
    }

    public function addDecision(Meeting $meeting, array $data): MeetingDecision
    {
        $maxOrder = $meeting->decisions()->max('sort_order') ?? 0;

        return $meeting->decisions()->create([
            'text'       => $data['text'],
            'category'   => $data['category'],
            'team'       => $data['team'] ?? null,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    public function updateDecision(MeetingDecision $decision, array $data): void
    {
        $decision->update([
            'text'     => $data['text'],
            'category' => $data['category'],
            'team'     => $data['team'] ?? null,
        ]);
    }

    public function deleteDecision(MeetingDecision $decision): void
    {
        $decision->delete();
    }

    public function attachDocument(Meeting $meeting, int $documentId): void
    {
        $meeting->documents()->syncWithoutDetaching([$documentId]);
    }

    public function attachDocuments(Meeting $meeting, array $documentIds, int $yearId): void
    {
        if (empty($documentIds)) {
            return;
        }

        $validIds = TeamDocument::whereIn('id', $documentIds)
            ->where('year_id', $yearId)
            ->pluck('id')
            ->all();

        if (! empty($validIds)) {
            $meeting->documents()->syncWithoutDetaching($validIds);
        }
    }

    public function detachDocument(Meeting $meeting, int $documentId): void
    {
        $meeting->documents()->detach($documentId);
    }

    // ── Privados ─────────────────────────────────────────────────────────────

    private function resolveSecretaryName(?int $secretaryId): ?string
    {
        if (! $secretaryId) {
            return null;
        }

        return User::find($secretaryId)?->name;
    }

    /**
     * Sincroniza la asistencia para los usuarios enviados desde el formulario.
     * Los registros de usuarios no incluidos (ej.: ahora inactivos) se conservan
     * intactos, preservando el historial.
     */
    private function syncAttendances(Meeting $meeting, array $attendances): void
    {
        if (empty($attendances)) {
            return;
        }

        $userIds = array_column($attendances, 'user_id');
        $names   = User::whereIn('id', $userIds)->pluck('name', 'id');

        // Solo reemplazar los registros de los usuarios incluidos en el envío
        $meeting->attendances()->whereIn('user_id', $userIds)->delete();

        foreach ($attendances as $att) {
            $userId = $att['user_id'];
            MeetingAttendance::create([
                'meeting_id' => $meeting->id,
                'user_id'    => $userId,
                'user_name'  => $names[$userId] ?? '(usuario desconocido)',
                'is_present' => (bool) $att['is_present'],
            ]);
        }
    }
}
