<?php

namespace App\Election\Core;

final class DomainDictionary
{
    public function appName(): string
    {
        return (string) config('election.dictionary.app_name', 'Alternative Election System');
    }

    public function operatorLabel(): string
    {
        return (string) config('election.dictionary.operator_label', 'Precinct Election Operations');
    }

    /**
     * @return array<int, array{id: string, label: string, description: string, stages: array<int, string>}>
     */
    public function workflow(): array
    {
        /** @var array<int, array{id: string, label: string, description: string, stages: array<int, string>}> $workflow */
        $workflow = config('election.dictionary.workflow', []);

        return $workflow;
    }

    public function stageLabel(string $stage): string
    {
        return (string) config("election.dictionary.stage.{$stage}", $stage);
    }

    public function ceremonyLabel(string $stage): string
    {
        return (string) config("election.dictionary.ceremony.{$stage}", $stage);
    }

    public function actionLabel(string $stage): string
    {
        return (string) config("election.dictionary.action.{$stage}", 'Continue');
    }
}
