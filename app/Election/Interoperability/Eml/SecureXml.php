<?php

namespace App\Election\Interoperability\Eml;

use DOMDocument;
use RuntimeException;

final class SecureXml
{
    public function load(string $xml): DOMDocument
    {
        $maximumBytes = max(1024, (int) config('election.eml.maximum_xml_bytes', 5_000_000));

        if (strlen($xml) > $maximumBytes) {
            throw new RuntimeException('EML artifact exceeds the configured size limit.');
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new RuntimeException('EML artifacts may not contain DTD or entity declarations.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = false;
            $document->formatOutput = false;

            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException($this->errorMessage('EML artifact is not well-formed XML.'));
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function canonicalize(string $xml): string
    {
        $canonical = $this->load($xml)->C14N(false, false);

        if (! is_string($canonical)) {
            throw new RuntimeException('EML artifact could not be canonicalized.');
        }

        return $canonical;
    }

    private function errorMessage(string $fallback): string
    {
        $error = libxml_get_last_error();

        return $error === false ? $fallback : trim($error->message);
    }
}
