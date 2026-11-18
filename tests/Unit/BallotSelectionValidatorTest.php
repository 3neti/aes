<?php

use App\Election\Voting\BallotSelectionValidator;

test('unknown contests cannot pass ballot selection validation', function (): void {
    $configuration = ['contests' => [[
        'id' => 'president_philippines',
        'title' => 'President',
        'max_selections' => 1,
        'candidates' => [['id' => 'candidate-1']],
    ]]];

    expect(fn () => (new BallotSelectionValidator)->validate($configuration, [
        'president' => ['candidate-1'],
    ]))->toThrow(RuntimeException::class, 'Unknown contest [president].');
});
