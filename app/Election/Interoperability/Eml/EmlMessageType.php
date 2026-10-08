<?php

namespace App\Election\Interoperability\Eml;

enum EmlMessageType: string
{
    case ElectionEvent = '110';
    case CandidateList = '230';
    case BallotDefinition = '410';
    case AuditLog = '480';
    case PrecinctCount = '510';
    case CanvassResult = '520';
    case Statistics = '530';

    public function schemaFilename(): string
    {
        return match ($this) {
            self::ElectionEvent => '110-electionevent-v7-0.xsd',
            self::CandidateList => '230-candidatelist-v7-0.xsd',
            self::BallotDefinition => '410-ballots-v7-0.xsd',
            self::AuditLog => '480-auditlog-v7-0.xsd',
            self::PrecinctCount => '510-count-v7-0.xsd',
            self::CanvassResult => '520-result-v7-0.xsd',
            self::Statistics => '530-stats-v7-0.xsd',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::ElectionEvent => 'election-event',
            self::CandidateList => 'candidate-list',
            self::BallotDefinition => 'ballot-definition',
            self::AuditLog => 'audit-log',
            self::PrecinctCount => 'precinct-count',
            self::CanvassResult => 'canvass-result',
            self::Statistics => 'statistics',
        };
    }
}
