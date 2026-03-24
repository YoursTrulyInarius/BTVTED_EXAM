<?php
/**
 * extract_text.php
 * Pure-PHP text extraction for PDF, DOCX, and PPTX files.
 * No external libraries or binaries required.
 */

/**
 * Main dispatcher — returns raw text from any supported file.
 */
function extract_text_from_file(string $filePath): string {
    if (!file_exists($filePath)) return '';
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'pdf':  return extract_text_from_pdf($filePath);
        case 'docx': return extract_text_from_docx($filePath);
        case 'pptx': return extract_text_from_pptx($filePath);
        case 'ppt':  return ''; // legacy binary, unsupported
        default:     return '';
    }
}

// ══════════════════════════════════════════════════════
//  PDF EXTRACTION  (pure PHP, no binary needed)
// ══════════════════════════════════════════════════════
function extract_text_from_pdf(string $filePath): string {
    $content = @file_get_contents($filePath);
    if ($content === false) return '';

    $text = '';

    // 1 ─ locate all stream / endstream blocks
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams);

    foreach ($streams[1] as $rawStream) {
        // Try zlib inflate (FlateDecode)
        $decoded = @gzuncompress($rawStream);
        if ($decoded === false) {
            // Some PDFs use raw deflate without the zlib header
            $decoded = @gzinflate($rawStream);
        }
        $data = ($decoded !== false) ? $decoded : $rawStream;

        // Extract text from PDF content operators
        $text .= pdf_extract_text_ops($data) . "\n";
    }

    // 2 ─ Fallback: pull literal strings from anywhere in the raw file
    if (strlen(trim($text)) < 100) {
        // Match parenthesized PDF string literals
        preg_match_all('/\(([^)]{2,})\)\s*Tj/s', $content, $m);
        $text .= implode(' ', $m[1]);

        preg_match_all('/\(([^)]{2,})\)/s', $content, $m2);
        $text .= implode(' ', $m2[1]);
    }

    return clean_extracted_text($text);
}

/**
 * Parse PDF content-stream operators and extract visible text.
 * Handles: Tj  TJ  '  "  BT/ET blocks
 */
function pdf_extract_text_ops(string $stream): string {
    $text = '';
    $inBT = false;

    // Tokenize stream into lines
    $lines = preg_split('/\r?\n/', $stream);
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === 'BT') { $inBT = true; continue; }
        if ($line === 'ET') { $inBT = false; $text .= ' '; continue; }

        if (!$inBT) continue;

        // Tj — show string: (Hello World) Tj
        if (preg_match('/\((.+)\)\s*Tj$/', $line, $m)) {
            $text .= pdf_decode_string($m[1]) . ' ';
        }
        // TJ — show glyph array: [(H) 10 (ello)] TJ
        elseif (preg_match('/\[(.+)\]\s*TJ$/', $line, $m)) {
            preg_match_all('/\(([^)]*)\)/', $m[1], $parts);
            foreach ($parts[1] as $p) {
                $text .= pdf_decode_string($p);
            }
            $text .= ' ';
        }
        // ' and " operators (move to next line then show string)
        elseif (preg_match('/\((.+)\)\s*[\'"]$/', $line, $m)) {
            $text .= "\n" . pdf_decode_string($m[1]) . ' ';
        }
        // Td / TD — newline hint
        elseif (preg_match('/\b(Td|TD|T\*)\b/', $line)) {
            $text .= ' ';
        }
    }

    return $text;
}

/** Decode common PDF string escapes (\n, \r, \t, \(, \), \\, octal) */
function pdf_decode_string(string $s): string {
    // Octal escapes like \101 → A
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
        return chr(octdec($m[1]));
    }, $s);
    // Standard escapes
    $s = strtr($s, [
        '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
        '\\(' => '(',  '\\)' => ')',  '\\\\' => '\\'
    ]);
    return $s;
}

// ══════════════════════════════════════════════════════
//  DOCX EXTRACTION
// ══════════════════════════════════════════════════════
function extract_text_from_docx(string $filePath): string {
    $text = '';
    $zip  = new ZipArchive;
    if ($zip->open($filePath) !== true) return '';

    $xmlString = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xmlString === false) return '';

    // Insert newlines at paragraph boundaries before stripping tags
    $xmlString = preg_replace('/<\/w:p>/', "\n", $xmlString);
    $xmlString = preg_replace('/<w:br[^>]*\/>/', ' ', $xmlString);

    // Strip all XML tags
    $text = strip_tags($xmlString);

    // Decode XML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return clean_extracted_text($text);
}

// ══════════════════════════════════════════════════════
//  PPTX EXTRACTION
// ══════════════════════════════════════════════════════
function extract_text_from_pptx(string $filePath): string {
    $text = '';
    $zip  = new ZipArchive;
    if ($zip->open($filePath) !== true) return '';

    for ($i = 1; $i <= 500; $i++) {
        $xmlString = $zip->getFromName("ppt/slides/slide{$i}.xml");
        if ($xmlString === false) break;

        $xmlString = preg_replace('/<\/a:p>/', "\n", $xmlString);
        $text .= strip_tags($xmlString) . "\n";
    }
    $zip->close();

    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return clean_extracted_text($text);
}

// ══════════════════════════════════════════════════════
//  TEXT CLEANING
// ══════════════════════════════════════════════════════
function clean_extracted_text(string $text): string {
    // Remove non-printable / control characters except newlines
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', ' ', $text);

    // Collapse runs of whitespace (but keep distinct newlines as sentence delimiters)
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Remove lines that are just numbers (page numbers, list bullets)
    $lines = explode("\n", $text);
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip very short lines, pure-number lines, or lines that are just symbols
        if (strlen($line) < 4) continue;
        if (preg_match('/^\d+$/', $line)) continue;
        $clean[] = $line;
    }

    return implode("\n", $clean);
}
