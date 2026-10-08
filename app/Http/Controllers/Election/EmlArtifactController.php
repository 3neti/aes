<?php

namespace App\Http\Controllers\Election;

use App\Election\Interoperability\Eml\EmlMessageType;
use App\Election\Interoperability\Eml\EmlSchemaRegistry;
use App\Election\Interoperability\Eml\SecureXml;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class EmlArtifactController extends Controller
{
    public function validateArtifact(Request $request, EmlSchemaRegistry $schemas, SecureXml $xml): JsonResponse
    {
        $validated = $request->validate([
            'artifact' => ['required', 'file', 'max:5120', 'mimetypes:application/xml,text/xml,text/plain'],
        ]);
        $contents = $validated['artifact']->get();

        try {
            $document = $xml->load($contents);
        } catch (RuntimeException $exception) {
            return response()->json([
                'valid' => false,
                'reason' => 'invalid_xml',
                'message' => $exception->getMessage(),
            ], 422);
        }
        $messageType = EmlMessageType::tryFrom((string) $document->documentElement?->getAttribute('Id'));

        if ($messageType === null) {
            return response()->json([
                'valid' => false,
                'reason' => 'unsupported_message_type',
                'message' => 'The XML does not declare a supported EML message Id.',
            ], 422);
        }

        $report = $schemas->validate($messageType, $contents);

        return response()->json([
            ...$report,
            'profile' => (string) config('election.eml.profile', 'waes-eml-7-base-1'),
            'artifact_sha256' => hash('sha256', $xml->canonicalize($contents)),
            'signature_status' => 'not_supplied',
            'message' => ($report['valid'] ?? false)
                ? 'The artifact is structurally valid for the WAES EML 7 Base Profile. No detached signature was supplied.'
                : 'The artifact failed EML schema validation.',
        ], ($report['valid'] ?? false) ? 200 : 422);
    }
}
