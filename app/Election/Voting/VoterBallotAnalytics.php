<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Str;

final class VoterBallotAnalytics
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('election.voter.analytics.enabled', false);
    }

    public function displayMode(): string
    {
        $mode = config('election.voter.analytics.display_mode', 'hidden');

        return in_array($mode, ['hidden', 'review', 'presentation'], true)
            ? $mode
            : 'hidden';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function record(array $input, array $context): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $sessionId = $this->sessionId((string) ($input['session_id'] ?? ''));
        $record = [
            'schema_version' => 'voter-ballot-analytics-1',
            'session_id' => $sessionId,
            'release_id' => $context['release_id'] ?? null,
            'precinct_id' => $context['precinct_id'] ?? null,
            'public_simulation_round_code' => $context['public_simulation_round_code'] ?? null,
            'public_simulation_precinct_code' => $context['public_simulation_precinct_code'] ?? null,
            'ballot_ui_profile' => $context['ballot_ui_profile'] ?? null,
            'selection_target' => $context['selection_target'] ?? null,
            'started_at' => $this->stringOrNull($input['started_at'] ?? null),
            'first_selection_at' => $this->stringOrNull($input['first_selection_at'] ?? null),
            'last_selection_at' => $this->stringOrNull($input['last_selection_at'] ?? null),
            'review_opened_at' => $this->stringOrNull($input['review_opened_at'] ?? null),
            'finalized_at' => $this->stringOrNull($input['finalized_at'] ?? null),
            'total_duration_seconds' => $this->nonNegativeInt($input['total_duration_seconds'] ?? null),
            'time_to_first_selection_seconds' => $this->nonNegativeInt($input['time_to_first_selection_seconds'] ?? null),
            'selection_edit_count' => $this->nonNegativeInt($input['selection_edit_count'] ?? null),
            'contest_navigation_clicks' => $this->nonNegativeInt($input['contest_navigation_clicks'] ?? null),
            'surname_navigation_clicks' => $this->nonNegativeInt($input['surname_navigation_clicks'] ?? null),
            'review_count' => $this->nonNegativeInt($input['review_count'] ?? null),
            'overvote_attempts_blocked' => $this->nonNegativeInt($input['overvote_attempts_blocked'] ?? null),
            'final_selection_count' => $this->nonNegativeInt($input['final_selection_count'] ?? null),
            'recorded_at' => now()->toIso8601String(),
            'privacy_notice' => 'Analytics excludes voter identity, control numbers, print PINs, candidate selections, QR payloads, and device identifiers.',
        ];

        $record['analytics_hash'] = hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->storage->writeJson("analytics/voter-sessions/{$sessionId}.json", $record);
        $this->journal->record('voting.analytics_recorded', [
            'session_id' => $sessionId,
            'release_id' => $record['release_id'],
            'total_duration_seconds' => $record['total_duration_seconds'],
            'ballot_ui_profile' => $record['ballot_ui_profile'],
            'selection_target' => $record['selection_target'],
        ]);

        return $this->summary($record);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function summary(array $record): array
    {
        return [
            'enabled' => true,
            'display_mode' => $this->displayMode(),
            'session_id' => $record['session_id'],
            'total_duration_seconds' => $record['total_duration_seconds'],
            'selection_edit_count' => $record['selection_edit_count'],
            'contest_navigation_clicks' => $record['contest_navigation_clicks'],
            'surname_navigation_clicks' => $record['surname_navigation_clicks'],
            'review_count' => $record['review_count'],
            'final_selection_count' => $record['final_selection_count'],
        ];
    }

    private function sessionId(string $value): string
    {
        $sessionId = Str::of($value)->lower()->replaceMatches('/[^a-z0-9-]/', '')->limit(80, '')->toString();

        return $sessionId !== '' ? $sessionId : (string) Str::uuid();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, (int) $value);
    }
}
