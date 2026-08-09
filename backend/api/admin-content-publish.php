<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
nexa_require_admin();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/importers/ImportDocument.php';
require_once __DIR__ . '/../planner/importers/MarkdownImportParser.php';
require_once __DIR__ . '/../planner/importers/DocxImportParser.php';

use Nexa\Planner\Importers\DocxImportParser;
use Nexa\Planner\Importers\ImportDocument;
use Nexa\Planner\Importers\MarkdownImportParser;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'POST is required.');
}

$body = nexa_read_json_body(12582912, 'Invalid content publish request.');
if (($body['confirm_publish'] ?? false) !== true) {
    nexa_safe_error(400, 'publish_confirmation_required', 'Explicit publish confirmation is required.');
}

try {
    $document = ImportDocument::fromRequest($body);
    $parser = $document['extension'] === 'md'
        ? new MarkdownImportParser()
        : new DocxImportParser();
    $preview = $parser->parse($document['bytes'], $document['metadata']);
} catch (InvalidArgumentException $error) {
    nexa_safe_error(422, 'content_validation_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_content_publish_parse_failed');
    nexa_safe_error(500, 'content_parse_failed', 'The content could not be validated for publishing.');
}

$source = $preview['source'];
$summary = $preview['summary'];
$blockingWarningCodes = [
    'english_only_content_required',
    'unrecognized_subject',
    'no_syllabus_records',
    'missing_subject_weight',
];

foreach ($preview['warnings'] as $warning) {
    if (in_array($warning['code'] ?? '', $blockingWarningCodes, true)) {
        nexa_safe_error(422, 'content_requires_correction', 'The preview contains content that must be corrected before publishing.');
    }
}

if (($summary['invalid_records'] ?? 0) > 0 || ($summary['language_warning_records'] ?? 0) > 0) {
    nexa_safe_error(422, 'content_requires_correction', 'The preview must contain only valid English academic records before publishing.');
}

if (!in_array($source['trust_level'], ['owner_verified', 'reviewer_verified'], true)) {
    nexa_safe_error(422, 'review_required', 'Only owner-verified or reviewer-verified content can be published.');
}

$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'database_unavailable', 'The content database is unavailable.');
}

$adminUserId = null;
$adminEmail = strtolower(trim((string)($_SESSION['admin_email'] ?? '')));
if ($adminEmail !== '') {
    $adminUserStatement = $db->prepare('SELECT id FROM users WHERE lower(email) = lower(?) LIMIT 1');
    $adminUserStatement->execute([$adminEmail]);
    $resolvedAdminUserId = $adminUserStatement->fetchColumn();
    $adminUserId = $resolvedAdminUserId === false ? null : (int)$resolvedAdminUserId;
}

$examStatement = $db->prepare('SELECT id FROM exams WHERE code = ? AND active = 1 LIMIT 1');
$examStatement->execute([$source['exam_code']]);
$examId = (int)$examStatement->fetchColumn();
if ($examId < 1) {
    nexa_safe_error(422, 'exam_not_found', 'The selected exam is not configured.');
}

$duplicateSourceStatement = $db->prepare(
    'SELECT id FROM content_sources WHERE file_checksum = ? AND archived_at IS NULL LIMIT 1'
);
$duplicateSourceStatement->execute([$document['checksum']]);
if ($duplicateSourceStatement->fetchColumn() !== false) {
    nexa_safe_error(409, 'duplicate_source', 'This exact document has already been imported.');
}

try {
    $db->beginTransaction();

    $sourceStatement = $db->prepare(
        'INSERT INTO content_sources
            (source_type, title, publisher, source_year, source_reference, original_filename,
             file_checksum, parser_version, ownership_confirmed, trust_level, imported_by, imported_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, CURRENT_TIMESTAMP)'
    );
    $sourceStatement->execute([
        $source['source_type'],
        $source['source_title'],
        $source['publisher'],
        $source['source_year'],
        $source['source_reference'],
        $document['filename'],
        $document['checksum'],
        $preview['parser_version'],
        $source['trust_level'],
        $adminUserId,
    ]);
    $sourceId = (int)$db->lastInsertId();

    $importStatement = $db->prepare(
        'INSERT INTO content_imports
            (source_id, format, status, total_records, valid_records, invalid_records,
             duplicate_records, validation_summary, created_by, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $importStatement->execute([
        $sourceId,
        $preview['format'],
        'uploaded',
        $summary['total_records'],
        $summary['valid_records'],
        $summary['invalid_records'],
        $summary['duplicate_records'],
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $adminUserId,
    ]);
    $importId = (int)$db->lastInsertId();

    $subjectIds = planner_subject_ids($db, $examId);
    $importedRecords = $source['source_type'] === 'syllabus'
        ? planner_publish_syllabus($db, $examId, $sourceId, $preview['records'], $subjectIds)
        : planner_publish_questions($db, $examId, $sourceId, $preview['records'], $subjectIds, $source['trust_level']);

    $db->prepare(
        "UPDATE content_imports
         SET status = 'imported', completed_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    )->execute([$importId]);

    $db->commit();
} catch (InvalidArgumentException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    nexa_safe_error(422, 'content_requires_correction', $error->getMessage());
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    nexa_log_event('planner_content_publish_failed');
    nexa_safe_error(500, 'content_publish_failed', 'The content could not be published.');
}

nexa_log_event('planner_content_published', [
    'source_type' => $source['source_type'],
    'format' => $preview['format'],
    'record_count' => $importedRecords,
]);

echo json_encode([
    'success' => true,
    'source_id' => $sourceId,
    'import_id' => $importId,
    'imported_records' => $importedRecords,
    'message' => 'Content published successfully.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function planner_subject_ids(PDO $db, int $examId): array
{
    $statement = $db->prepare(
        'SELECT id, code, name_en, official_weight_percent
         FROM exam_subjects
         WHERE exam_id = ? AND active = 1'
    );
    $statement->execute([$examId]);

    $subjects = [];
    foreach ($statement->fetchAll() as $row) {
        $subjects[strtoupper((string)$row['code'])] = [
            'id' => (int)$row['id'],
            'name_en' => (string)$row['name_en'],
            'official_weight_percent' => (float)$row['official_weight_percent'],
        ];
    }
    return $subjects;
}

function planner_publish_syllabus(PDO $db, int $examId, int $sourceId, array $records, array $subjectIds): int
{
    $subjectRecords = [];
    foreach ($records as $record) {
        if (($record['record_type'] ?? '') !== 'subject') {
            continue;
        }
        $code = strtoupper((string)($record['code'] ?? ''));
        if (!isset($subjectIds[$code])) {
            throw new InvalidArgumentException('Syllabus contains an unconfigured subject: ' . $code);
        }
        if ($record['official_weight_percent'] === null) {
            throw new InvalidArgumentException('Every syllabus subject needs an official weight.');
        }
        $subjectRecords[$code] = true;
    }

    $unitStatement = $db->prepare(
        'INSERT OR IGNORE INTO syllabus_units
            (exam_id, subject_id, code, title_en, scope_tag, default_difficulty, estimated_minutes, source_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $topicStatement = $db->prepare(
        'INSERT OR IGNORE INTO syllabus_topics
            (unit_id, code, title_en, scope_tag, default_difficulty, estimated_minutes, source_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $unitIdStatement = $db->prepare(
        'SELECT id, subject_id, title_en FROM syllabus_units WHERE exam_id = ? AND code = ? LIMIT 1'
    );
    $topicIdStatement = $db->prepare(
        'SELECT id, title_en FROM syllabus_topics WHERE unit_id = ? AND code = ? LIMIT 1'
    );

    $unitIds = [];
    $count = 0;

    foreach ($records as $record) {
        $type = $record['record_type'] ?? '';
        if ($type === 'unit') {
            $subjectCode = strtoupper((string)$record['subject_code']);
            if (!isset($subjectIds[$subjectCode])) {
                throw new InvalidArgumentException('Unit references an unconfigured subject.');
            }
            $unitStatement->execute([
                $examId,
                $subjectIds[$subjectCode]['id'],
                $record['code'],
                $record['title_en'],
                $record['scope_tag'],
                $record['default_difficulty'] ?? 'standard',
                (int)$record['estimated_minutes'],
                $sourceId,
            ]);
            $unitIdStatement->execute([$examId, $record['code']]);
            $unit = $unitIdStatement->fetch();
            if (!$unit || (int)$unit['subject_id'] !== $subjectIds[$subjectCode]['id']) {
                throw new InvalidArgumentException('Unit mapping conflicts with existing content.');
            }
            if ((string)$unit['title_en'] !== (string)$record['title_en']) {
                throw new InvalidArgumentException('Existing unit wording conflicts with this import.');
            }
            $unitIds[$record['code']] = (int)$unit['id'];
            $count++;
        } elseif ($type === 'topic') {
            $unitCode = (string)$record['unit_code'];
            if (!isset($unitIds[$unitCode])) {
                throw new InvalidArgumentException('Topic references a unit that was not imported.');
            }
            $topicStatement->execute([
                $unitIds[$unitCode],
                $record['code'],
                $record['title_en'],
                $record['scope_tag'],
                $record['default_difficulty'] ?? 'standard',
                (int)$record['estimated_minutes'],
                $sourceId,
            ]);
            $topicIdStatement->execute([$unitIds[$unitCode], $record['code']]);
            $topic = $topicIdStatement->fetch();
            if (!$topic || (string)$topic['title_en'] !== (string)$record['title_en']) {
                throw new InvalidArgumentException('Existing topic wording conflicts with this import.');
            }
            $count++;
        }
    }

    if ($subjectRecords === []) {
        throw new InvalidArgumentException('The syllabus contains no subject records.');
    }

    return $count;
}

function planner_publish_questions(PDO $db, int $examId, int $sourceId, array $records, array $subjectIds, string $trustLevel): int
{
    $questionStatement = $db->prepare(
        'INSERT INTO questions
            (exam_id, subject_id, source_id, question_year, paper_session, question_number,
             category, question_type, prompt_en, correct_option_key, explanation_en, difficulty,
             authoritative, publication_state, trust_level)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $optionStatement = $db->prepare(
        'INSERT INTO question_options
            (question_id, option_key, option_text_en, display_order)
         VALUES (?, ?, ?, ?)'
    );
    $duplicateStatement = $db->prepare(
        'SELECT id FROM questions
         WHERE exam_id = ? AND subject_id = ? AND question_year = ?
           AND LOWER(prompt_en) = LOWER(?) LIMIT 1'
    );

    $seen = [];
    $count = 0;

    foreach ($records as $record) {
        $subjectCode = strtoupper((string)($record['subject_code'] ?? ''));
        if (!isset($subjectIds[$subjectCode])) {
            throw new InvalidArgumentException('Question references an unconfigured subject.');
        }

        $year = filter_var($record['question_year'] ?? null, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1900 || $year > 2200) {
            throw new InvalidArgumentException('Every PYQ needs a valid question year.');
        }

        $category = (string)($record['category'] ?? '');
        if (!in_array($category, ['CATEGORY_I', 'CATEGORY_II'], true)) {
            throw new InvalidArgumentException('Every question needs a valid category.');
        }

        $prompt = trim((string)($record['prompt_en'] ?? ''));
        $fingerprint = $subjectCode . '|' . $year . '|' . strtolower(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        if (isset($seen[$fingerprint])) {
            throw new InvalidArgumentException('Duplicate question found within this import.');
        }
        $seen[$fingerprint] = true;

        $duplicateStatement->execute([$examId, $subjectIds[$subjectCode]['id'], $year, $prompt]);
        if ($duplicateStatement->fetchColumn() !== false) {
            throw new InvalidArgumentException('A question with the same year, subject, and wording already exists.');
        }

        $questionStatement->execute([
            $examId,
            $subjectIds[$subjectCode]['id'],
            $sourceId,
            $year,
            $record['paper_session'],
            $record['question_number'],
            $category,
            'mcq',
            $prompt,
            $record['correct_option_key'],
            $record['explanation_en'],
            'standard',
            1,
            'published',
            $trustLevel,
        ]);
        $questionId = (int)$db->lastInsertId();

        $displayOrder = 0;
        foreach ($record['options'] as $optionKey => $optionText) {
            $optionStatement->execute([
                $questionId,
                $optionKey,
                $optionText,
                $displayOrder++,
            ]);
        }
        $count++;
    }

    if ($count === 0) {
        throw new InvalidArgumentException('No questions were found in this import.');
    }

    return $count;
}