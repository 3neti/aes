<?php

namespace App\Election\Interoperability\Eml;

use App\Election\Core\CanonicalJson;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class WaesEml7Profile implements EmlProfile
{
    private const EmlNamespace = 'urn:oasis:names:tc:evs:schema:eml';

    public function __construct(private readonly CanonicalJson $json) {}

    public function id(): string
    {
        return 'waes-eml-7-base-1';
    }

    public function version(): string
    {
        return '7.0';
    }

    /** @param array<string, mixed> $context */
    public function serialize(EmlMessageType $messageType, array $context): string
    {
        $document = $this->document($messageType, $context);

        match ($messageType) {
            EmlMessageType::ElectionEvent => $this->appendElectionEvent($document, $context),
            EmlMessageType::CandidateList => $this->appendCandidateList($document, $context),
            EmlMessageType::BallotDefinition => $this->appendBallotDefinition($document, $context),
            EmlMessageType::AuditLog => $this->appendAuditLog($document, $context),
            EmlMessageType::PrecinctCount => $this->appendCount($document, $context, false),
            EmlMessageType::CanvassResult => $this->appendResult($document, $context),
            EmlMessageType::Statistics => $this->appendCount($document, $context, true),
        };

        $xml = $document->saveXML();

        if (! is_string($xml)) {
            throw new RuntimeException('Unable to serialize the EML artifact.');
        }

        return $xml;
    }

    /** @param array<string, mixed> $context */
    private function document(EmlMessageType $messageType, array $context): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::EmlNamespace, 'EML');
        $root->setAttribute('Id', $messageType->value);
        $root->setAttribute('SchemaVersion', $this->version());
        $document->appendChild($root);

        $header = $this->element($document, $root, 'EMLHeader');
        $this->element($document, $header, 'TransactionId', $this->transactionId($messageType, $context));
        $official = $this->element($document, $header, 'OfficialStatusDetail');
        $this->element($document, $official, 'OfficialStatus', (string) ($context['official_status'] ?? 'official'));
        $this->element($document, $official, 'StatusDate', $this->statusDate($context));

        return $document;
    }

    /** @param array<string, mixed> $context */
    private function appendElectionEvent(DOMDocument $document, array $context): void
    {
        $configuration = $this->configuration($context);
        $event = $this->element($document, $document->documentElement, 'ElectionEvent');
        $control = $this->element($document, $event, 'ElectionControl');
        $this->appendEventIdentifier($document, $control, $configuration);
        $electionIdentifier = $this->element($document, $control, 'ElectionIdentifier');
        $electionIdentifier->setAttribute('IdNumber', $this->tokenId($configuration['election_id'] ?? 'WAES-ELECTION'));
        $electionIdentifier->setAttribute('DisplayOrder', '1');
        $this->element($document, $electionIdentifier, 'ElectionName', $this->electionName($configuration));
        $date = $this->element($document, $control, 'ElectionDate');
        $date->setAttribute('Type', 'election-day');
        $this->element($document, $date, 'SingleDate', $this->statusDate($context));
        $this->element($document, $control, 'ElectionScope', 'Full');
        $this->element($document, $control, 'ElectionType', 'General Election');
        $this->element($document, $control, 'ElectionJurisdiction', 'Republic of the Philippines');

        $election = $this->element($document, $event, 'Election');
        $this->appendElectionIdentifier($document, $election, $configuration);

        foreach ($this->contests($configuration) as $contest) {
            $contestElement = $this->element($document, $election, 'Contest');
            $this->appendContestIdentifier($document, $contestElement, $contest);
            $this->element($document, $contestElement, 'VotingMethod', 'other');
            $this->element($document, $contestElement, 'MaxVotes', (string) max(1, (int) ($contest['max_selections'] ?? 1)));
            $this->element($document, $contestElement, 'NumberOfPositions', (string) max(1, (int) ($contest['max_selections'] ?? 1)));
        }

    }

    /** @param array<string, mixed> $context */
    private function appendCandidateList(DOMDocument $document, array $context): void
    {
        $configuration = $this->configuration($context);
        $list = $this->element($document, $document->documentElement, 'CandidateList');
        $this->appendEventIdentifier($document, $list, $configuration);
        $election = $this->element($document, $list, 'Election');
        $this->appendElectionIdentifier($document, $election, $configuration);

        foreach ($this->contests($configuration) as $contest) {
            $contestElement = $this->element($document, $election, 'Contest');
            $this->appendContestIdentifier($document, $contestElement, $contest);

            foreach ($this->candidates($contest) as $candidate) {
                $this->appendCandidate($document, $contestElement, $candidate);
            }
        }

    }

    /** @param array<string, mixed> $context */
    private function appendBallotDefinition(DOMDocument $document, array $context): void
    {
        $configuration = $this->configuration($context);
        $ballots = $this->element($document, $document->documentElement, 'Ballots');
        $this->appendEventIdentifier($document, $ballots, $configuration);
        $ballot = $this->element($document, $ballots, 'Ballot');
        $reportingUnit = $this->element($document, $ballot, 'ReportingUnitIdentifier', (string) ($configuration['precinct_id'] ?? 'unknown-precinct'));
        $reportingUnit->setAttribute('IdNumber', $this->tokenId($configuration['precinct_id'] ?? 'unknown-precinct'));
        $election = $this->element($document, $ballot, 'Election');
        $this->appendElectionIdentifier($document, $election, $configuration);

        foreach ($this->contests($configuration) as $contest) {
            $contestElement = $this->element($document, $election, 'Contest');
            $this->appendContestIdentifier($document, $contestElement, $contest);
            $this->element($document, $contestElement, 'VotingMethod', 'other');
            $this->element($document, $contestElement, 'MaxVotes', (string) max(1, (int) ($contest['max_selections'] ?? 1)));
            $choices = $this->element($document, $contestElement, 'BallotChoices');

            foreach ($this->candidates($contest) as $candidate) {
                $this->appendCandidate($document, $choices, $candidate);
            }
        }

        $ballotIdentifier = $this->element($document, $ballot, 'BallotIdentifier');
        $ballotIdentifier->setAttribute('IdNumber', $this->tokenId($configuration['ballot_style_id'] ?? 'WAES-BALLOT'));
        $this->element($document, $ballotIdentifier, 'BallotName', (string) ($configuration['ballot_style_id'] ?? 'WAES Ballot'));
    }

    /** @param array<string, mixed> $context */
    private function appendCount(DOMDocument $document, array $context, bool $statistics): void
    {
        $configuration = $this->configuration($context);
        $result = $this->result($context);
        $container = $this->element($document, $document->documentElement, $statistics ? 'Statistics' : 'Count');
        $this->appendEventIdentifier($document, $container, $configuration);
        $election = $this->element($document, $container, 'Election');
        $this->appendElectionIdentifier($document, $election, $configuration);
        $contests = $this->element($document, $election, 'Contests');

        foreach ($this->contests($configuration) as $contest) {
            $contestId = (string) ($contest['id'] ?? 'contest');
            $contestElement = $this->element($document, $contests, 'Contest');

            if ($statistics) {
                $contestElement->setAttribute('ReportType', 'WAES canonical tally');
            }

            $this->appendContestIdentifier($document, $contestElement, $contest);
            $qualifier = $this->element($document, $contestElement, 'CountQualifier');
            $this->element($document, $qualifier, 'Simulation', $this->isSimulation($context) ? 'yes' : 'no');
            $this->element($document, $qualifier, 'Final', (bool) ($context['final'] ?? false) ? 'yes' : 'no');
            $this->element($document, $contestElement, 'NumberOfPositions', (string) max(1, (int) ($contest['max_selections'] ?? 1)));
            $totalVotes = $this->element($document, $contestElement, 'TotalVotes');

            foreach ($this->candidates($contest) as $candidate) {
                $candidateId = (string) ($candidate['id'] ?? 'candidate');
                $selection = $this->element($document, $totalVotes, 'Selection');
                $this->appendCandidate($document, $selection, $candidate);
                $this->element($document, $selection, 'ValidVotes', (string) max(0, (int) data_get($result, "tally.{$contestId}.{$candidateId}", 0)));
            }

            $accepted = max(0, (int) ($result['accepted_ballots'] ?? 0));
            $rejected = max(0, (int) ($result['rejected_ballots'] ?? 0));
            $this->element($document, $totalVotes, 'Cast', (string) ($accepted + $rejected));
            $this->element($document, $totalVotes, 'Read', (string) $accepted);
            $this->element($document, $totalVotes, 'TotalCounted', (string) $accepted);
        }

    }

    /** @param array<string, mixed> $context */
    private function appendResult(DOMDocument $document, array $context): void
    {
        $configuration = $this->configuration($context);
        $result = $this->result($context);
        $container = $this->element($document, $document->documentElement, 'Result');
        $this->appendEventIdentifier($document, $container, $configuration);
        $election = $this->element($document, $container, 'Election');
        $this->appendElectionIdentifier($document, $election, $configuration);

        foreach ($this->contests($configuration) as $contest) {
            $contestId = (string) ($contest['id'] ?? 'contest');
            $contestElement = $this->element($document, $election, 'Contest');
            $this->appendContestIdentifier($document, $contestElement, $contest);
            $ranked = collect($this->candidates($contest))
                ->map(fn (array $candidate): array => [
                    'candidate' => $candidate,
                    'votes' => max(0, (int) data_get($result, "tally.{$contestId}.{$candidate['id']}", 0)),
                ])
                ->sortByDesc('votes')
                ->values();
            $positions = max(1, (int) ($contest['max_selections'] ?? 1));

            foreach ($ranked as $index => $rank) {
                $selection = $this->element($document, $contestElement, 'Selection');
                $this->appendCandidate($document, $selection, $rank['candidate']);

                if ($rank['votes'] > 0) {
                    $this->element($document, $selection, 'Votes', (string) $rank['votes']);
                }

                $this->element($document, $selection, 'Ranking', (string) ($index + 1));
                $this->element($document, $selection, 'Elected', $index < $positions && $rank['votes'] > 0 ? 'yes' : 'no');
            }
        }

    }

    /** @param array<string, mixed> $context */
    private function appendAuditLog(DOMDocument $document, array $context): void
    {
        $configuration = $this->configuration($context);
        $audit = $this->element($document, $document->documentElement, 'AuditLog');
        $this->appendEventIdentifier($document, $audit, $configuration);
        $this->appendElectionIdentifier($document, $audit, $configuration);
        $this->element($document, $audit, 'Update', 'no');
        $entries = collect((array) ($context['audit_entries'] ?? []))->take(10_000);

        if ($entries->isEmpty()) {
            $entries = collect([['event_type' => 'audit.exported', 'event_hash' => $this->json->hash($context)]]);
        }

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $loggedSeal = $this->element($document, $audit, 'LoggedSeal');
            $seal = $this->element($document, $loggedSeal, 'Seal');
            $otherSeal = $this->element($document, $seal, 'OtherSeal');
            $otherSeal->setAttribute('Type', 'WAES activity journal digest');
            $digest = base64_encode(hash('sha256', (string) ($entry['event_hash'] ?? $this->json->hash($entry)), true));
            $signatureValue = $document->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue', $digest);
            $otherSeal->appendChild($signatureValue);
            $this->element($document, $loggedSeal, 'Message', (string) ($entry['event_type'] ?? 'audit event'));
        }
    }

    /** @param array<string, mixed> $candidate */
    private function appendCandidate(DOMDocument $document, DOMElement $parent, array $candidate): void
    {
        $candidateElement = $this->element($document, $parent, 'Candidate');
        $identifier = $this->element($document, $candidateElement, 'CandidateIdentifier');
        $identifier->setAttribute('IdNumber', $this->tokenId($candidate['id'] ?? 'candidate'));
        $this->element($document, $identifier, 'CandidateName', (string) ($candidate['name'] ?? $candidate['id'] ?? 'Candidate'));
        $this->element($document, $candidateElement, 'StatusDetails');
    }

    /** @param array<string, mixed> $configuration */
    private function appendEventIdentifier(DOMDocument $document, DOMElement $parent, array $configuration): void
    {
        $identifier = $this->element($document, $parent, 'EventIdentifier');
        $identifier->setAttribute('IdNumber', $this->tokenId($configuration['election_id'] ?? 'WAES-ELECTION'));
        $this->element($document, $identifier, 'EventName', $this->electionName($configuration));
    }

    /** @param array<string, mixed> $configuration */
    private function appendElectionIdentifier(DOMDocument $document, DOMElement $parent, array $configuration): void
    {
        $identifier = $this->element($document, $parent, 'ElectionIdentifier');
        $identifier->setAttribute('IdNumber', $this->tokenId($configuration['election_id'] ?? 'WAES-ELECTION'));
        $this->element($document, $identifier, 'ElectionName', $this->electionName($configuration));
    }

    /** @param array<string, mixed> $contest */
    private function appendContestIdentifier(DOMDocument $document, DOMElement $parent, array $contest): void
    {
        $identifier = $this->element($document, $parent, 'ContestIdentifier');
        $identifier->setAttribute('IdNumber', $this->tokenId($contest['id'] ?? 'contest'));
        $this->element($document, $identifier, 'ContestName', (string) ($contest['title'] ?? $contest['office'] ?? $contest['id'] ?? 'Contest'));
    }

    private function element(DOMDocument $document, ?DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $document->createElementNS(self::EmlNamespace, $name);

        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }

        $parent?->appendChild($element);

        return $element;
    }

    /** @param array<string, mixed> $context */
    private function configuration(array $context): array
    {
        $configuration = $context['configuration'] ?? null;

        if (! is_array($configuration)) {
            throw new RuntimeException('EML serialization requires an election configuration.');
        }

        return $configuration;
    }

    /** @param array<string, mixed> $context */
    private function result(array $context): array
    {
        return is_array($context['result'] ?? null) ? $context['result'] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function contests(array $configuration): array
    {
        return collect((array) ($configuration['contests'] ?? []))
            ->filter(fn (mixed $contest): bool => is_array($contest) && ($contest['id'] ?? '') !== '')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function candidates(array $contest): array
    {
        return collect((array) ($contest['candidates'] ?? []))
            ->filter(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['id'] ?? '') !== '')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $context */
    private function transactionId(EmlMessageType $messageType, array $context): string
    {
        return sprintf('WAES-%s-%s', $messageType->value, substr($this->json->hash($context), 0, 24));
    }

    /** @param array<string, mixed> $context */
    private function statusDate(array $context): string
    {
        $date = (string) ($context['election_date'] ?? config('election.eml.status_date', '2022-05-09'));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '2022-05-09';
    }

    /** @param array<string, mixed> $context */
    private function isSimulation(array $context): bool
    {
        return (string) ($context['run_type'] ?? config('election.runtime.run_type', 'rehearsal')) !== 'election_day';
    }

    /** @param array<string, mixed> $configuration */
    private function electionName(array $configuration): string
    {
        return (string) ($configuration['election_name'] ?? config('election.election_return_form.election_label', $configuration['election_id'] ?? 'WAES Election'));
    }

    private function tokenId(mixed $value): string
    {
        $token = preg_replace('/[^A-Za-z0-9._:-]+/', '-', trim((string) $value));

        return $token === null || $token === '' ? 'unknown' : $token;
    }
}
