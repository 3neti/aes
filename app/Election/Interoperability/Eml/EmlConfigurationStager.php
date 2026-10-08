<?php

namespace App\Election\Interoperability\Eml;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class EmlConfigurationStager
{
    public function __construct(
        private readonly EmlSchemaRegistry $schemas,
        private readonly SecureXml $xml,
        private readonly CanonicalJson $json,
        private readonly ElectionStorage $storage,
    ) {}

    /**
     * @param  array<string, string>  $artifacts
     * @return array<string, mixed>
     */
    public function stage(array $artifacts): array
    {
        foreach ([EmlMessageType::ElectionEvent, EmlMessageType::CandidateList, EmlMessageType::BallotDefinition] as $type) {
            $contents = $artifacts[$type->value] ?? null;

            if (! is_string($contents) || $contents === '') {
                throw new RuntimeException("EML {$type->value} is required for configuration staging.");
            }

            if (! ($this->schemas->validate($type, $contents)['valid'] ?? false)) {
                throw new RuntimeException("EML {$type->value} failed schema validation.");
            }
        }

        $event = $this->xpath($artifacts['110']);
        $candidates = $this->xpath($artifacts['230']);
        $ballot = $this->xpath($artifacts['410']);
        $electionIds = [
            $this->value($event, '/eml:EML/eml:ElectionEvent/eml:ElectionControl/eml:ElectionIdentifier/@IdNumber'),
            $this->value($candidates, '/eml:EML/eml:CandidateList/eml:Election/eml:ElectionIdentifier/@IdNumber'),
            $this->value($ballot, '/eml:EML/eml:Ballots/eml:Ballot/eml:Election/eml:ElectionIdentifier/@IdNumber'),
        ];

        if (count(array_unique($electionIds)) !== 1 || $electionIds[0] === '') {
            throw new RuntimeException('The staged EML artifacts do not identify the same election.');
        }

        $contestNodes = $candidates->query('/eml:EML/eml:CandidateList/eml:Election/eml:Contest');
        $contests = [];

        foreach ($contestNodes ?: [] as $contestNode) {
            $contestId = $this->value($candidates, 'eml:ContestIdentifier/@IdNumber', $contestNode);
            $ballotContest = $ballot->query('/eml:EML/eml:Ballots/eml:Ballot/eml:Election/eml:Contest[eml:ContestIdentifier/@IdNumber="'.$contestId.'"]')->item(0);

            if ($contestId === '' || $ballotContest === null) {
                throw new RuntimeException('Candidate-list and ballot-definition contests do not match.');
            }

            $contestCandidates = [];

            foreach ($candidates->query('eml:Candidate', $contestNode) ?: [] as $candidateNode) {
                $candidateId = $this->value($candidates, 'eml:CandidateIdentifier/@IdNumber', $candidateNode);
                $contestCandidates[] = [
                    'id' => $candidateId,
                    'name' => $this->value($candidates, 'eml:CandidateIdentifier/eml:CandidateName', $candidateNode) ?: $candidateId,
                ];
            }

            $contests[] = [
                'id' => $contestId,
                'title' => $this->value($candidates, 'eml:ContestIdentifier/eml:ContestName', $contestNode) ?: $contestId,
                'max_selections' => max(1, (int) $this->value($ballot, 'eml:MaxVotes', $ballotContest)),
                'candidates' => $contestCandidates,
            ];
        }

        $canonical = [
            'election_id' => $electionIds[0],
            'precinct_id' => $this->value($ballot, '/eml:EML/eml:Ballots/eml:Ballot/eml:ReportingUnitIdentifier/@IdNumber'),
            'ballot_style_id' => $this->value($ballot, '/eml:EML/eml:Ballots/eml:Ballot/eml:BallotIdentifier/@IdNumber'),
            'contests' => $contests,
        ];
        $staging = [
            'schema_version' => 'waes-eml-configuration-staging-1',
            'profile' => (string) config('election.eml.profile', 'waes-eml-7-base-1'),
            'status' => 'staged_not_active',
            ...$canonical,
            'source_artifacts' => collect($artifacts)
                ->map(fn (string $contents): string => hash('sha256', $this->xml->canonicalize($contents)))
                ->all(),
            'mapping_hash' => $this->json->hash($canonical),
        ];
        $staging['staging_id'] = 'eml-'.substr($this->json->hash($staging), 0, 20);
        $this->storage->writeJson("interoperability/eml/staging/{$staging['staging_id']}.json", $staging);

        return $staging;
    }

    private function xpath(string $xml): DOMXPath
    {
        $xpath = new DOMXPath($this->xml->load($xml));
        $xpath->registerNamespace('eml', 'urn:oasis:names:tc:evs:schema:eml');

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $expression, ?DOMNode $contextNode = null): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')', $contextNode));
    }
}
