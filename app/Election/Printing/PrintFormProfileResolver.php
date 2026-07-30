<?php

namespace App\Election\Printing;

use Illuminate\Validation\ValidationException;

final class PrintFormProfileResolver
{
    public function default(): PrintFormProfile
    {
        return $this->from((string) config('election.print_forms.default_profile', PrintFormProfile::A4->value));
    }

    /**
     * @return array<int, PrintFormProfile>
     */
    public function available(): array
    {
        $configured = config('election.print_forms.available_profiles', [
            PrintFormProfile::A4->value,
            PrintFormProfile::Thermal80->value,
            PrintFormProfile::Thermal58->value,
        ]);

        return collect($configured)
            ->filter(fn (mixed $profile): bool => is_string($profile) && PrintFormProfile::tryFrom($profile) !== null)
            ->map(fn (string $profile): PrintFormProfile => PrintFormProfile::from($profile))
            ->unique(fn (PrintFormProfile $profile): string => $profile->value)
            ->values()
            ->all();
    }

    public function from(?string $profile): PrintFormProfile
    {
        $resolved = PrintFormProfile::tryFrom((string) $profile);

        if ($resolved === null || ! in_array($resolved, $this->available(), true)) {
            throw ValidationException::withMessages([
                'profile' => 'The selected print form is unavailable on this precinct appliance.',
            ]);
        }

        return $resolved;
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, width_mm: int, thermal: bool}>
     */
    public function options(): array
    {
        return collect($this->available())
            ->map(fn (PrintFormProfile $profile): array => [
                'value' => $profile->value,
                'label' => $profile->label(),
                'description' => $profile->description(),
                'width_mm' => $profile->widthMillimetres(),
                'thermal' => $profile->isThermal(),
            ])
            ->all();
    }
}
