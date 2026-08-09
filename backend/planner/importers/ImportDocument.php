<?php
declare(strict_types=1);

namespace Nexa\Planner\Importers;

use InvalidArgumentException;

final class ImportDocument
{
    public const MAX_BYTES = 8388608;

    public static function fromRequest(array $body): array
    {
        $filename = trim((string)($body['filename'] ?? ''));
        if ($filename === '' || strlen($filename) > 255 || preg_match('/[\\\\\/\x00-\x1F]/', $filename)) {
            throw new InvalidArgumentException('A safe document filename is required.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['md', 'docx'], true)) {
            throw new InvalidArgumentException('Only Markdown .md and Word .docx files are supported.');
        }

        if (!isset($body['metadata']) || !is_array($body['metadata'])) {
            throw new InvalidArgumentException('Document metadata is required.');
        }

        if ($extension === 'md') {
            $bytes = $body['content'] ?? null;
            if (!is_string($bytes)) {
                throw new InvalidArgumentException('Markdown content is required.');
            }
        } else {
            $encoded = $body['content_base64'] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                throw new InvalidArgumentException('DOCX content_base64 is required.');
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false) {
                throw new InvalidArgumentException('DOCX content is not valid base64.');
            }
        }

        if (strlen($bytes) === 0) {
            throw new InvalidArgumentException('The document is empty.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The document is too large.');
        }

        $metadata = $body['metadata'];
        $metadata['original_filename'] = $filename;

        return [
            'filename' => $filename,
            'extension' => $extension,
            'bytes' => $bytes,
            'metadata' => $metadata,
            'checksum' => hash('sha256', $bytes),
        ];
    }
}