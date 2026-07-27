<?php

namespace App\Election\ReviewRoom;

enum ReviewStationRole: string
{
    case Officer = 'officer';
    case Voter = 'voter';
    case PrintStation = 'print_station';
    case Watcher = 'watcher';
    case Presentation = 'presentation';

    public function label(): string
    {
        return match ($this) {
            self::Officer => 'Election Officer',
            self::Voter => 'Voter Tablet',
            self::PrintStation => 'Private Print Station',
            self::Watcher => 'Poll Watcher',
            self::Presentation => 'Presentation Screen',
        };
    }

    public function destinationRoute(): string
    {
        return match ($this) {
            self::Officer => 'election.home',
            self::Voter => 'election.voter',
            self::PrintStation => 'election.print-station',
            self::Watcher => 'election.watchers',
            self::Presentation => 'election.review-room.presentation',
        };
    }
}
