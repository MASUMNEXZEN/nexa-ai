<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
nexa_require_admin();

require_once __DIR__ . '/../planner/importers/ImportDocument.php';
require_once __DIR__ . '/../planner/importers/MarkdownImportParser.php';
require_once __DIR__ . '/../planner/importers/DocxImportParser.php';

use Nexa\Planner\Importers\DocxImportParser;
use Nexa\Planner\Importers\ImportDocument;
use Nexa\Planner\Importers\MarkdownImportParser;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'POST is required.');
}

$body = nexa_read_json_body(12582912, 'Invalid content preview request.');

try {
    $document = ImportDocument::fromRequest($body);
    $parser = $document['extension'] === 'md'
        ? new MarkdownImportParser()
        : new DocxImportParser();
    $preview = $parser->parse($document['bytes'], $document['metadata']);
} catch (InvalidArgumentException $error) {
    nexa_safe_error(422, 'content_validation_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_content_preview_failed');
    nexa_safe_error(500, 'content_preview_failed', 'The content preview could not be generated.');
}

nexa_log_event('planner_content_preview', [
    'source_type' => $preview['source']['source_type'],
    'format' => $preview['format'],
    'record_count' => $preview['summary']['total_records'],
    'invalid_count' => $preview['summary']['invalid_records'],
]);

echo json_encode([
    'success' => true,
    'preview' => $preview,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);