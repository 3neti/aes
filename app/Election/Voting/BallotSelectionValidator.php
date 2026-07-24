<?php

namespace App\Election\Voting;

use RuntimeException;

final class BallotSelectionValidator
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<int, string>>  $selections
     */
    public function validate(array $configuration, array $selections): void
    {
        foreach ($configuration['contests'] as $contest) {
            $selected = array_values(array_unique($selections[$contest['id']] ?? []));
            $candidateIds = collect($contest['candidates'])->pluck('id')->all();

            if (count($selected) > $contest['max_selections']) {
                throw new RuntimeException("Too many selections for {$contest['title']}.");
            }

            foreach ($selected as $candidateId) {
                if (! in_array($candidateId, $candidateIds, true)) {
                    throw new RuntimeException("Invalid candidate [{$candidateId}].");
                }
            }
        }
    }
}
