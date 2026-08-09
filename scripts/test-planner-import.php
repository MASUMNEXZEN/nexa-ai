<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/planner/importers/MarkdownImportParser.php';
require_once __DIR__ . '/../backend/planner/importers/DocxImportParser.php';
require_once __DIR__ . '/../backend/planner/importers/ImportDocument.php';

use Nexa\Planner\Importers\DocxImportParser;
use Nexa\Planner\Importers\ImportDocument;
use Nexa\Planner\Importers\MarkdownImportParser;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$parser = new MarkdownImportParser();
$metadata = [
    'source_type' => 'syllabus',
    'source_title' => 'Fixture syllabus',
    'exam_code' => 'WB_ANM_GNM',
    'trust_level' => 'owner_verified',
    'ownership_confirmed' => true,
];

$syllabus = $parser->parse(
    "# Fixture\n\n## LS: Life Science\n\n**Default planner weight:** 43.48%\n\n### LS-01: Cells\n\n**Scope:** CORE + PYQ_EXT\n\n- [ ] Cell membrane\n- [ ] Nucleus\n",
    $metadata
);
$assert($syllabus['summary']['total_records'] === 4, 'Syllabus fixture should produce one subject, one unit, and two topics.');
$assert($syllabus['summary']['invalid_records'] === 0, 'Syllabus fixture should be structurally valid.');
$assert($syllabus['records'][3]['code'] === 'LS-01-T02', 'Topic codes must be stable within a unit.');

$pyqMetadata = [
    'source_type' => 'pyq',
    'source_title' => 'Fixture PYQ',
    'exam_code' => 'WB_ANM_GNM',
    'source_year' => 2025,
    'trust_level' => 'owner_verified',
    'ownership_confirmed' => true,
];

$pyq = $parser->parse(
    "## 2025\n\n### Life Science\n\n#### Category I - Questions 1-1\n\n**Q1. Which organelle contains genetic material?**\n\n- **A.** Ribosome\n- **B.** Nucleus\n- **C.** Lysosome\n- **D.** Golgi body\n\n**Correct answer:** (B) Nucleus\n\n**Explanation:** The nucleus contains chromosomes and controls cell activity.\n",
    $pyqMetadata
);
$assert($pyq['summary']['total_records'] === 1, 'PYQ fixture should produce one question.');
$assert($pyq['summary']['valid_records'] === 1, 'PYQ fixture should be structurally valid.');
$assert($pyq['records'][0]['correct_option_key'] === 'B', 'Correct answer key must be preserved.');

$bengali = $parser->parse(
    "## 2025\n\n### Life Science\n\n#### Category I - Questions 1-1\n\n**Q1. কোষ কী?**\n\n- **A.** কোষ\n- **B.** দেহ\n\n**Correct answer:** (A) কোষ\n",
    $pyqMetadata
);
$assert($bengali['summary']['language_warning_records'] === 1, 'Non-English academic text must be flagged.');


$docxPath = tempnam(sys_get_temp_dir(), 'nexa-test-docx-');
$zip = new ZipArchive();
$assert($docxPath !== false && $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'DOCX fixture archive should open.');
$labels = [
    'Year: 2025',
    'Subject: LS | Life Science',
    'Category: CATEGORY_I',
    'Question: 1',
    'Prompt: Which organelle contains genetic material?',
    'Option A: Ribosome',
    'Option B: Nucleus',
    'Option C: Lysosome',
    'Option D: Golgi body',
    'Correct answer: B',
    'Explanation: The nucleus contains chromosomes and controls cell activity.',
];
$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
foreach ($labels as $label) {
    $xml .= '<w:p><w:r><w:t>' . htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
}
$xml .= '</w:body></w:document>';
$zip->addFromString('word/document.xml', $xml);
$zip->close();

$docxPayload = ImportDocument::fromRequest([
    'filename' => 'fixture.docx',
    'content_base64' => base64_encode((string)file_get_contents($docxPath)),
    'metadata' => $pyqMetadata,
]);
$docx = (new DocxImportParser())->parse($docxPayload['bytes'], $docxPayload['metadata']);
$assert($docx['format'] === 'docx', 'DOCX parser must identify its format.');
$assert($docx['summary']['total_records'] === 1, 'DOCX fixture should produce one question.');
$assert($docx['summary']['valid_records'] === 1, 'DOCX fixture should be structurally valid.');
$assert($docx['records'][0]['correct_option_key'] === 'B', 'DOCX correct answer must be preserved.');
@unlink($docxPath);

echo "Planner import tests passed.\n";