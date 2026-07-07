# PDF Text Extraction

## Purpose

PDF extraction is isolated behind an internal adapter so COMELEC PDF sources can be parsed without coupling election importers to one command-line tool.

Current adapter:

```text
PdfTextExtractor
  -> GhostscriptPdfTextExtractor
```

`GhostscriptPdfTextExtractor` runs:

```bash
gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -o - source.pdf
```

The Ghostscript binary is configurable:

```text
election.pdf.ghostscript_binary
ELECTION_PDF_GHOSTSCRIPT_BINARY
```

## Extraction Contract

The extractor returns page-level text records:

```text
page number
text
```

Importers are responsible for parsing text into domain records and preserving source file/page references.

## Operational Rule

PDF text extraction is not legal truth. The PDF remains source evidence. Parsed files are deterministic read models and must include source hashes plus needs-review output for ambiguous rows.

## Failure Mode

If Ghostscript is unavailable or extraction fails, commands fail clearly and journal the import failure. Install Ghostscript or configure `ELECTION_PDF_GHOSTSCRIPT_BINARY` to point to the correct executable.
