<?php

namespace App\Election\Lifecycle;

final class Lifecycle
{
    public const Provision = 'provision';

    public const Certification = 'certification';

    public const OpenPrecinct = 'open_precinct';

    public const OpenPolls = 'open_polls';

    public const Voting = 'voting';

    public const ClosePolls = 'close_polls';

    public const Counting = 'counting';

    public const ElectionReturn = 'election_return';

    public const ClosePrecinct = 'close_precinct';

    public const Audit = 'audit';

    /**
     * @return array<int, string>
     */
    public static function stages(): array
    {
        return [
            self::Provision,
            self::Certification,
            self::OpenPrecinct,
            self::OpenPolls,
            self::Voting,
            self::ClosePolls,
            self::Counting,
            self::ElectionReturn,
            self::ClosePrecinct,
            self::Audit,
        ];
    }

    public static function next(string $stage): ?string
    {
        $stages = self::stages();
        $index = array_search($stage, $stages, true);

        if ($index === false) {
            return null;
        }

        return $stages[$index + 1] ?? null;
    }
}
