<?php

namespace App\Election\Returns;

final class ElectionReturnContestScopes
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function configurationFor(array $configuration, ElectionReturnScope $scope): array
    {
        if ($scope === ElectionReturnScope::Combined) {
            return $configuration;
        }

        $configuration['contests'] = collect($configuration['contests'] ?? [])
            ->filter(fn (array $contest): bool => $this->scopeFor($contest) === $scope)
            ->values()
            ->all();

        return $configuration;
    }

    /** @param array<string, mixed> $contest */
    public function scopeFor(array $contest): ElectionReturnScope
    {
        $key = $this->normalizedContestKey($contest);

        foreach ($this->nationalNeedles() as $needle) {
            if (str_contains($key, $needle)) {
                return ElectionReturnScope::National;
            }
        }

        return ElectionReturnScope::Local;
    }

    /** @param array<string, mixed> $contest */
    private function normalizedContestKey(array $contest): string
    {
        $parts = [
            $contest['id'] ?? '',
            $contest['office'] ?? '',
            $contest['title'] ?? '',
        ];

        return (string) str(implode(' ', array_map('strval', $parts)))
            ->lower()
            ->replace(['-', '_', '/', ','], ' ')
            ->squish();
    }

    /** @return array<int, string> */
    private function nationalNeedles(): array
    {
        return [
            'president',
            'vice president',
            'senator',
            'party list',
            'partylist',
        ];
    }
}
