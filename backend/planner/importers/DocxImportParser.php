<?php
declare(strict_types=1);

namespace Nexa\Planner\Importers;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

final class DocxImportParser
{
    public const PARSER_VERSION = 'docx-v1';
    private const MAX_UNCOMPRESSED_BYTES = 25165824;

    public function parse(string $binary, array $metadata): array
    {
        if ($binary === '') {
            throw new InvalidArgumentException('DOCX content cannot be empty.');
        }
        if (strlen($binary) > ImportDocument::MAX_BYTES) {
            throw new InvalidArgumentException('DOCX content is too large.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'nexa-docx-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $binary) === false) {
            throw new InvalidArgumentException('DOCX could not be staged for parsing.');
        }

        $zip = new ZipArchive();
        try {
            if ($zip->open($temporaryPath) !== true) {
                throw new InvalidArgumentException('The DOCX archive could not be opened.');
            }

            $uncompressedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $uncompressedBytes += (int)($stat['size'] ?? 0);
                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new InvalidArgumentException('The DOCX archive expands beyond the safe size limit.');
                }
            }

            $documentIndex = $zip->locateName('word/document.xml', ZipArchive::FL_NOCASE);
            if ($documentIndex === false) {
                throw new InvalidArgumentException('The DOCX document body is missing.');
            }

            $xml = $zip->getFromIndex($documentIndex);
            if (!is_string($xml) || $xml === '') {
                throw new InvalidArgumentException('The DOCX document body is empty.');
            }
        } finally {
            $zip->close();
            @unlink($temporaryPath);
        }

        $paragraphs = $this->extractParagraphs($xml);
        if ($paragraphs === []) {
            throw new InvalidArgumentException('The DOCX contains no readable paragraphs.');
        }

        $markdown = $this->toCanonicalMarkdown($paragraphs, $metadata);
        $parser = new MarkdownImportParser();
        $result = $parser->parse($markdown, $metadata);
        $result['format'] = 'docx';
        $result['parser_version'] = self::PARSER_VERSION;
        return $result;
    }

    private function extractParagraphs(string $xml): array
    {
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$loaded) {
            throw new InvalidArgumentException('The DOCX XML is invalid.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphNodes = $xpath->query('//w:body/w:p');
        if ($paragraphNodes === false) {
            return [];
        }

        $paragraphs = [];
        foreach ($paragraphNodes as $paragraphNode) {
            $parts = [];
            $textNodes = $xpath->query('.//w:t | .//w:tab | .//w:br', $paragraphNode);
            if ($textNodes !== false) {
                foreach ($textNodes as $textNode) {
                    if ($textNode->localName === 'tab' || $textNode->localName === 'br') {
                        $parts[] = ' ';
                    } else {
                        $parts[] = (string)$textNode->textContent;
                    }
                }
            }

            $paragraph = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? implode('', $parts));
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    private function toCanonicalMarkdown(array $paragraphs, array $metadata): string
    {
        $sourceType = strtolower(trim((string)($metadata['source_type'] ?? '')));
        $lines = [];
        $currentQuestionNumber = null;
        $lastOutputIndex = null;
        $warnings = [];

        foreach ($paragraphs as $paragraph) {
            $labelData = $this->labelData($paragraph);
            if ($labelData === null) {
                if ($lastOutputIndex !== null && isset($lines[$lastOutputIndex])) {
                    $lines[$lastOutputIndex] .= ' ' . $paragraph;
                } else {
                    $warnings[] = 'unlabeled_paragraph';
                }
                continue;
            }

            [$label, $value] = $labelData;
            $normalizedLabel = strtolower(preg_replace('/\s+/u', ' ', trim($label)) ?? trim($label));

            if ($sourceType === 'syllabus') {
                $line = $this->syllabusLine($normalizedLabel, $value);
                if ($line !== null) {
                    $lines[] = $line;
                    $lastOutputIndex = count($lines) - 1;
                } else {
                    $warnings[] = 'unsupported_syllabus_label';
                }
                continue;
            }

            if ($sourceType !== 'pyq') {
                throw new InvalidArgumentException('DOCX source_type must be syllabus or pyq.');
            }

            if ($normalizedLabel === 'year') {
                $parts = $this->splitValue($value);
                $year = (int)$parts[0];
                $session = $parts[1] ?? '';
                $lines[] = $session === '' ? '## ' . $year : '## ' . $year . ' - ' . $session;
                $lastOutputIndex = count($lines) - 1;
            } elseif ($normalizedLabel === 'subject') {
                $lines[] = '### ' . $this->displayValue($value);
                $lastOutputIndex = count($lines) - 1;
            } elseif ($normalizedLabel === 'category') {
                $category = strtoupper(str_replace(['CATEGORY_', 'CATEGORY '], '', trim($value)));
                $lines[] = '#### Category ' . $category;
                $lastOutputIndex = count($lines) - 1;
            } elseif ($normalizedLabel === 'question') {
                [$number, $prompt] = $this->questionValue($value);
                $currentQuestionNumber = $number;
                if ($prompt !== '') {
                    $lines[] = '**Q' . $number . '. ' . $prompt . '**';
                    $lastOutputIndex = count($lines) - 1;
                }
            } elseif ($normalizedLabel === 'prompt') {
                if ($currentQuestionNumber === null) {
                    $warnings[] = 'prompt_without_question';
                    continue;
                }
                $lines[] = '**Q' . $currentQuestionNumber . '. ' . $value . '**';
                $lastOutputIndex = count($lines) - 1;
            } elseif (preg_match('/^option\s*([A-D])$/i', $normalizedLabel, $match)) {
                $lines[] = '- **' . strtoupper($match[1]) . '.** ' . $value;
                $lastOutputIndex = count($lines) - 1;
            } elseif (in_array($normalizedLabel, ['correct answer', 'answer'], true)) {
                $answer = strtoupper(trim(preg_split('/[\s|:-]+/u', $value)[0] ?? ''));
                $lines[] = '**Correct answer:** (' . $answer . ') ' . $value;
                $lastOutputIndex = count($lines) - 1;
            } elseif ($normalizedLabel === 'explanation') {
                $lines[] = '**Explanation:** ' . $value;
                $lastOutputIndex = count($lines) - 1;
            } else {
                $warnings[] = 'unsupported_question_label';
            }
        }

        if ($warnings !== []) {
            throw new InvalidArgumentException(
                'The DOCX contains unsupported or unlabeled template fields: ' . implode(', ', array_unique($warnings))
            );
        }

        return implode("\n\n", $lines);
    }

    private function syllabusLine(string $label, string $value): ?string
    {
        $parts = $this->splitValue($value);
        $code = strtoupper(trim($parts[0] ?? ''));
        $title = trim($parts[1] ?? '');

        if ($label === 'subject') {
            return '## ' . $code . ': ' . ($title !== '' ? $title : $code);
        }
        if ($label === 'unit') {
            return '### ' . $code . ': ' . ($title !== '' ? $title : $code);
        }
        if ($label === 'topic') {
            return '- [ ] [' . $code . '] ' . ($title !== '' ? $title : $code);
        }
        if ($label === 'scope') {
            return '**Scope:** ' . $value;
        }
        if ($label === 'default planner weight' || $label === 'weight') {
            return '**Default planner weight:** ' . trim($value, " %") . '%';
        }

        return null;
    }

    private function labelData(string $paragraph): ?array
    {
        if (!preg_match('/^\[?([A-Za-z][A-Za-z ]+)\]?\s*:\s*(.+)$/u', $paragraph, $match)) {
            return null;
        }
        return [trim($match[1]), trim($match[2])];
    }

    private function splitValue(string $value): array
    {
        $parts = preg_split('/\s*\|\s*/u', trim($value), 2);
        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }

        if (preg_match('/^([A-Z]{2}(?:-\d+)?(?:-[A-Z0-9_-]+)*)\s*[-:]\s*(.+)$/u', trim($value), $match)) {
            return [trim($match[1]), trim($match[2])];
        }

        return [trim($value)];
    }

    private function questionValue(string $value): array
    {
        if (preg_match('/^(\d+)\s*\|\s*(.+)$/u', trim($value), $match)) {
            return [(string)$match[1], trim($match[2])];
        }
        if (preg_match('/^(\d+)\s*[-:]\s*(.+)$/u', trim($value), $match)) {
            return [(string)$match[1], trim($match[2])];
        }
        if (preg_match('/^\d+$/', trim($value))) {
            return [trim($value), ''];
        }
        throw new InvalidArgumentException('Question must include a number.');
    }

    private function displayValue(string $value): string
    {
        $parts = $this->splitValue($value);
        return $parts[1] ?? $parts[0];
    }
}