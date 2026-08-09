<?php
declare(strict_types=1);

namespace Nexa\Planner\Importers;

use InvalidArgumentException;

final class MarkdownImportParser
{
    public const PARSER_VERSION = 'markdown-v1';

    private const SUBJECTS = [
        'LS' => 'Life Science',
        'PS' => 'Physical Science',
        'EN' => 'English',
        'MA' => 'Mathematics',
        'GK' => 'General Knowledge',
        'LR' => 'Logical Reasoning',
    ];

    public function parse(string $markdown, array $metadata): array
    {
        $content = trim($markdown);
        if ($content === '') {
            throw new InvalidArgumentException('Markdown content cannot be empty.');
        }

        $sourceType = strtolower(trim((string)($metadata['source_type'] ?? '')));
        if (!in_array($sourceType, ['syllabus', 'pyq'], true)) {
            throw new InvalidArgumentException('Markdown preview requires source_type syllabus or pyq.');
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $metadata = $this->normalizeMetadata($metadata, $sourceType);

        $result = $sourceType === 'syllabus'
            ? $this->parseSyllabus($lines, $metadata)
            : $this->parsePyq($lines, $metadata);

        $result['parser_version'] = self::PARSER_VERSION;
        $result['format'] = 'markdown';
        $result['source'] = $metadata;
        $result['summary'] = $this->summarize($result['records'], $result['warnings']);

        return $result;
    }

    private function normalizeMetadata(array $metadata, string $sourceType): array
    {
        $title = trim((string)($metadata['source_title'] ?? ''));
        $examCode = strtoupper(trim((string)($metadata['exam_code'] ?? '')));
        $trustLevel = strtolower(trim((string)($metadata['trust_level'] ?? '')));
        $ownershipConfirmed = filter_var(
            $metadata['ownership_confirmed'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($title === '') {
            throw new InvalidArgumentException('source_title is required.');
        }
        if ($examCode === '') {
            throw new InvalidArgumentException('exam_code is required.');
        }
        if (!in_array($trustLevel, ['owner_verified', 'reviewer_verified', 'unverified'], true)) {
            throw new InvalidArgumentException('trust_level is invalid.');
        }
        if ($ownershipConfirmed !== true) {
            throw new InvalidArgumentException('ownership_confirmed must be true for canonical imports.');
        }

        $sourceYear = $metadata['source_year'] ?? null;
        if ($sourceYear !== null && $sourceYear !== '') {
            $sourceYear = filter_var($sourceYear, FILTER_VALIDATE_INT);
            if ($sourceYear === false || $sourceYear < 1900 || $sourceYear > 2200) {
                throw new InvalidArgumentException('source_year must be a valid year.');
            }
        } else {
            $sourceYear = null;
        }

        return [
            'source_type' => $sourceType,
            'source_title' => $title,
            'exam_code' => $examCode,
            'source_year' => $sourceYear === null ? null : (int)$sourceYear,
            'publisher' => trim((string)($metadata['publisher'] ?? '')),
            'source_reference' => trim((string)($metadata['source_reference'] ?? '')),
            'trust_level' => $trustLevel,
            'ownership_confirmed' => true,
        ];
    }

    private function parseSyllabus(array $lines, array $metadata): array
    {
        $records = [];
        $warnings = [];
        $subjectCode = null;
        $subjectName = null;
        $subjectWeight = null;
        $unitCode = null;
        $unitTitle = null;
        $unitScope = null;
        $topicNumber = 0;
        $seenCodes = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim((string)$line);

            if (preg_match('/^##\s+([A-Z]{2}):\s*(.+)$/u', $line, $match)) {
                $subjectCode = strtoupper($match[1]);
                $subjectName = $this->cleanText($match[2]);
                $subjectWeight = null;
                $unitCode = null;
                $unitTitle = null;
                $unitScope = null;
                $topicNumber = 0;

                if (!isset(self::SUBJECTS[$subjectCode])) {
                    $warnings[] = $this->warning($lineNumber + 1, 'unrecognized_subject', $subjectCode);
                    continue;
                }

                $records[] = [
                    'record_type' => 'subject',
                    'code' => $subjectCode,
                    'name_en' => $subjectName,
                    'official_weight_percent' => null,
                    'line' => $lineNumber + 1,
                ];
                $seenCodes[$subjectCode] = true;
                continue;
            }

            if ($subjectCode !== null && preg_match('/^\*\*Default planner weight:\*\*\s*([\d.]+)%/u', $line, $match)) {
                $subjectWeight = (float)$match[1];
                for ($index = count($records) - 1; $index >= 0; $index--) {
                    if ($records[$index]['record_type'] === 'subject' && $records[$index]['code'] === $subjectCode) {
                        $records[$index]['official_weight_percent'] = $subjectWeight;
                        break;
                    }
                }
                continue;
            }

            if ($subjectCode !== null && preg_match('/^###\s+([A-Z]{2}-\d+):\s*(.+)$/u', $line, $match)) {
                $unitCode = strtoupper($match[1]);
                $unitTitle = $this->cleanText($match[2]);
                $unitScope = null;
                $topicNumber = 0;
                $codeKey = 'unit:' . $unitCode;

                if (isset($seenCodes[$codeKey])) {
                    $warnings[] = $this->warning($lineNumber + 1, 'duplicate_unit_code', $unitCode);
                }
                $seenCodes[$codeKey] = true;
                $records[] = [
                    'record_type' => 'unit',
                    'subject_code' => $subjectCode,
                    'code' => $unitCode,
                    'title_en' => $unitTitle,
                    'scope_tag' => null,
                    'estimated_minutes' => 30,
                    'line' => $lineNumber + 1,
                ];
                continue;
            }

            if ($unitCode !== null && preg_match('/^\*\*Scope:\*\*\s*(.+)$/u', $line, $match)) {
                $unitScope = $this->extractScope($match[1]);
                for ($index = count($records) - 1; $index >= 0; $index--) {
                    if ($records[$index]['record_type'] === 'unit' && $records[$index]['code'] === $unitCode) {
                        $records[$index]['scope_tag'] = $unitScope;
                        break;
                    }
                }
                continue;
            }

            if ($unitCode !== null && preg_match('/^-\s+\[[ xX]\]\s+(.+)$/u', $line, $match)) {
                $topicNumber++;
                $topicCode = $unitCode . '-T' . str_pad((string)$topicNumber, 2, '0', STR_PAD_LEFT);
                $topicTitle = $this->cleanText($match[1]);
                $explicitTopicCode = null;
                if (preg_match('/^\[([A-Z]{2}-\d+(?:-[A-Z0-9_-]+)*)\]\s*(.+)$/u', $topicTitle, $topicMatch)) {
                    $explicitTopicCode = strtoupper($topicMatch[1]);
                    $topicTitle = $this->cleanText($topicMatch[2]);
                }
                $topicCode = $explicitTopicCode ?? $topicCode;
                $codeKey = 'topic:' . $topicCode;

                if (isset($seenCodes[$codeKey])) {
                    $warnings[] = $this->warning($lineNumber + 1, 'duplicate_topic_code', $topicCode);
                }
                $seenCodes[$codeKey] = true;
                $records[] = [
                    'record_type' => 'topic',
                    'subject_code' => $subjectCode,
                    'unit_code' => $unitCode,
                    'code' => $topicCode,
                    'title_en' => $topicTitle,
                    'scope_tag' => $unitScope,
                    'default_difficulty' => 'standard',
                    'estimated_minutes' => 30,
                    'line' => $lineNumber + 1,
                ];
            }
        }

        foreach ($records as $index => $record) {
            if ($record['record_type'] === 'subject' && $record['official_weight_percent'] === null) {
                $warnings[] = $this->warning($record['line'], 'missing_subject_weight', $record['code']);
            }

            if ($this->containsUnsupportedScript($this->recordAcademicText($record))) {
                $records[$index]['language_warning'] = 'unsupported_script_detected';
                $warnings[] = $this->warning(
                    $record['line'],
                    'english_only_content_required',
                    'Unsupported non-English script detected in academic text.'
                );
            }
        }

        if ($records === []) {
            $warnings[] = $this->warning(1, 'no_syllabus_records', 'No supported subject, unit, or topic headings were found.');
        }

        return [
            'records' => $records,
            'warnings' => $warnings,
        ];
    }

    private function parsePyq(array $lines, array $metadata): array
    {
        $records = [];
        $warnings = [];
        $year = $metadata['source_year'];
        $session = null;
        $subjectCode = null;
        $category = null;
        $question = null;
        $lastField = null;

        $flush = function () use (&$question, &$records, &$warnings, &$lastField): void {
            if ($question === null) {
                return;
            }

            $record = $question;
            $record['prompt_en'] = $this->cleanText((string)($record['prompt_en'] ?? ''));
            $record['explanation_en'] = $this->cleanText((string)($record['explanation_en'] ?? ''));
            $record['validation_errors'] = [];

            if ($record['prompt_en'] === '') {
                $record['validation_errors'][] = 'missing_question_text';
            }
            if (count($record['options']) !== 4) {
                $record['validation_errors'][] = 'invalid_option_count';
            }
            if ($record['correct_option_key'] === null) {
                $record['validation_errors'][] = 'missing_correct_answer';
            } elseif (!isset($record['options'][$record['correct_option_key']])) {
                $record['validation_errors'][] = 'correct_answer_not_in_options';
            }

            $academicText = $record['prompt_en'] . ' ' . $record['explanation_en'] . ' ' . implode(' ', $record['options']);
            if ($this->containsUnsupportedScript($academicText)) {
                $record['language_warning'] = 'unsupported_script_detected';
                $warnings[] = $this->warning($record['line'], 'english_only_content_required', 'Unsupported non-English script detected in academic text.');
            }

            $records[] = $record;
            $question = null;
            $lastField = null;
        };

        foreach ($lines as $lineNumber => $line) {
            $line = trim((string)$line);

            if (preg_match('/^##\s+(\d{4})(?:\s*-\s*(.+))?$/u', $line, $match)) {
                $flush();
                $year = (int)$match[1];
                $session = isset($match[2]) ? $this->cleanText($match[2]) : null;
                $subjectCode = null;
                $category = null;
                continue;
            }

            if (preg_match('/^###\s+(.+)$/u', $line, $match)) {
                $flush();
                $subjectCode = $this->subjectCodeFromName($match[1]);
                $category = null;
                if ($subjectCode === null) {
                    $warnings[] = $this->warning($lineNumber + 1, 'unrecognized_subject', $match[1]);
                }
                continue;
            }

            if (preg_match('/^####\s+Category\s+(I|II)\b/iu', $line, $match)) {
                $flush();
                $category = 'CATEGORY_' . strtoupper($match[1]);
                continue;
            }

            if (preg_match('/^\*\*Q(\d+)\.\s*(.+?)\*\*\s*$/u', $line, $match)) {
                $flush();
                $question = [
                    'record_type' => 'question',
                    'question_number' => (string)$match[1],
                    'question_year' => $year,
                    'paper_session' => $session,
                    'subject_code' => $subjectCode,
                    'category' => $category,
                    'prompt_en' => $this->cleanText($match[2]),
                    'options' => [],
                    'correct_option_key' => null,
                    'explanation_en' => '',
                    'line' => $lineNumber + 1,
                ];
                $lastField = 'prompt_en';
                continue;
            }

            if ($question !== null && preg_match('/^-\s+\*\*([A-D])\.\*\*\s*(.+)$/u', $line, $match)) {
                $question['options'][strtoupper($match[1])] = $this->cleanText($match[2]);
                $lastField = 'option';
                continue;
            }

            if ($question !== null && preg_match('/^\*\*[^*]+:\*\*\s*\(([A-D])\)/u', $line, $match)) {
                $question['correct_option_key'] = strtoupper($match[1]);
                $lastField = 'answer';
                continue;
            }

            if ($question !== null && preg_match('/^\*\*[^*]+:\*\*\s*(.+)$/u', $line, $match)) {
                $question['explanation_en'] = $this->cleanText($match[1]);
                $lastField = 'explanation_en';
                continue;
            }

            if ($question !== null && $line !== '') {
                if ($lastField === 'explanation_en') {
                    $question['explanation_en'] .= ' ' . $line;
                } elseif ($lastField === 'prompt_en') {
                    $question['prompt_en'] .= ' ' . $line;
                }
            }
        }

        $flush();

        return [
            'records' => $records,
            'warnings' => $warnings,
        ];
    }

    private function summarize(array $records, array $warnings): array
    {
        $invalid = 0;
        $duplicates = 0;
        $languageWarnings = 0;

        foreach ($records as $index => $record) {
            if (!empty($record['validation_errors'])) {
                $invalid++;
            }
            if (($record['language_warning'] ?? '') !== '') {
                $languageWarnings++;
            }
        }

        foreach ($warnings as $warning) {
            if (($warning['code'] ?? '') === 'duplicate_unit_code' || ($warning['code'] ?? '') === 'duplicate_topic_code') {
                $duplicates++;
            }
        }

        return [
            'total_records' => count($records),
            'valid_records' => count($records) - $invalid,
            'invalid_records' => $invalid,
            'duplicate_records' => $duplicates,
            'language_warning_records' => $languageWarnings,
            'warning_count' => count($warnings),
        ];
    }

    private function warning(int $line, string $code, string $message): array
    {
        return [
            'line' => $line,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[_*]+/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return $text;
    }

    private function containsUnsupportedScript(string $text): bool
    {
        return preg_match('/[\x{0590}-\x{08FF}\x{0900}-\x{1FFF}\x{2E80}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text) === 1;
    }

    private function recordAcademicText(array $record): string
    {
        return implode(' ', array_filter([
            (string)($record['name_en'] ?? ''),
            (string)($record['title_en'] ?? ''),
            (string)($record['scope_tag'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    private function extractScope(string $scope): ?string
    {
        if (preg_match('/\b(CORE|PYQ_EXT)\b/i', $scope, $match)) {
            return strtoupper($match[1]);
        }
        return null;
    }

    private function subjectCodeFromName(string $name): ?string
    {
        $cleanName = $this->cleanText($name);
        $cleanName = preg_replace('/\s*\([^)]*\)\s*$/u', '', $cleanName) ?? $cleanName;
        $normalized = strtolower($cleanName);

        foreach (self::SUBJECTS as $code => $displayName) {
            if ($normalized === strtolower($displayName)) {
                return $code;
            }
        }

        if (str_contains($normalized, 'basic english')) {
            return 'EN';
        }
        if (str_contains($normalized, 'life science')) {
            return 'LS';
        }
        if (str_contains($normalized, 'physical science')) {
            return 'PS';
        }
        if (str_contains($normalized, 'general knowledge')) {
            return 'GK';
        }
        if (str_contains($normalized, 'logical reasoning')) {
            return 'LR';
        }
        if (str_contains($normalized, 'mathematics')) {
            return 'MA';
        }

        return null;
    }
}
