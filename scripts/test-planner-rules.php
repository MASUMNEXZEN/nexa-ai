<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/planner/domain/PlannerRules.php';
require_once __DIR__ . '/../backend/planner/domain/PlannerEngine.php';

use Nexa\Planner\Domain\PlannerRules;
use Nexa\Planner\Domain\PlannerEngine;


$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$defaultProfile = PlannerRules::normalizeProfile([]);
$assert($defaultProfile['daily_minutes'] === 180, 'Missing daily capacity must default to 180 minutes.');
$assert($defaultProfile['preferred_session_minutes'] === 50, 'Missing session length must default to 50 minutes.');

$fullTouchpoints = PlannerRules::allocateSubjectTouchpoints(180, [1, 2, 3]);
$assert(count($fullTouchpoints) === 4, 'A day should respect the preferred session length while preserving three subjects.');
$assert(array_sum(array_column($fullTouchpoints, 'estimated_minutes')) === 180, 'Touchpoints must preserve daily capacity.');
$assert(count(array_filter($fullTouchpoints, static fn(array $item): bool => $item['coverage_mode'] === 'full')) === 4, '180 minutes should produce full touchpoints.');

$lightTouchpoints = PlannerRules::allocateSubjectTouchpoints(30, [1, 2, 3]);
$assert(count($lightTouchpoints) === 3, 'Light days must still cover three subjects.');
$assert(array_sum(array_column($lightTouchpoints, 'estimated_minutes')) === 30, 'Light touchpoints must preserve the selected capacity.');
$assert(count(array_filter($lightTouchpoints, static fn(array $item): bool => $item['coverage_mode'] === 'light')) === 3, 'A 30-minute day must be labeled light coverage.');

$threw = false;
try {
    PlannerRules::normalizeSubjectIds([1, 2]);
} catch (\InvalidArgumentException) {
    $threw = true;
}
$assert($threw, 'Fewer than three subjects must be rejected.');

$threw = false;
try {
    PlannerRules::normalizeSessionMinutes(29);
} catch (\InvalidArgumentException) {
    $threw = true;
}
$assert($threw, 'A full session below 30 minutes must be rejected.');


$subjects = [
    ['id' => 1, 'code' => 'LS', 'name_en' => 'Life Science', 'official_weight_percent' => 43.48, 'display_order' => 1],
    ['id' => 2, 'code' => 'PS', 'name_en' => 'Physical Science', 'official_weight_percent' => 21.74, 'display_order' => 2],
    ['id' => 3, 'code' => 'EN', 'name_en' => 'English', 'official_weight_percent' => 13.04, 'display_order' => 3],
];
$topics = [
    ['id' => 11, 'unit_id' => 101, 'subject_id' => 1, 'code' => 'LS-01-T01', 'title_en' => 'Cell biology', 'scope_tag' => 'CORE', 'estimated_minutes' => 30],
    ['id' => 12, 'unit_id' => 102, 'subject_id' => 2, 'code' => 'PS-01-T01', 'title_en' => 'Matter', 'scope_tag' => 'CORE', 'estimated_minutes' => 30],
    ['id' => 13, 'unit_id' => 103, 'subject_id' => 3, 'code' => 'EN-01-T01', 'title_en' => 'Grammar', 'scope_tag' => 'CORE', 'estimated_minutes' => 30],
];
$generation = PlannerEngine::generateMonth(
    new DateTimeImmutable('2026-08-10'),
    new DateTimeImmutable('2026-08-11'),
    ['daily_minutes' => 180, 'preferred_session_minutes' => 50],
    [
        ['weekday' => 1, 'available' => 1, 'minutes' => 180],
        ['weekday' => 2, 'available' => 1, 'minutes' => 30],
    ],
    $subjects,
    $topics,
    [],
    ['1' => 1, '2' => 2, '3' => 3]
);
$assert(count($generation['tasks']) === 7, 'Each available day must contain at least three subject tasks.');
$assert($generation['summary']['total_minutes'] === 210, 'Generated tasks must preserve daily capacities.');
$assert($generation['summary']['light_coverage_days'] === 1, 'Thirty-minute days must be marked as light coverage.');
$firstTaskTitles = array_column($generation['tasks'], 'title_en');
$repeatGeneration = PlannerEngine::generateMonth(
    new DateTimeImmutable('2026-08-10'),
    new DateTimeImmutable('2026-08-11'),
    ['daily_minutes' => 180, 'preferred_session_minutes' => 50],
    [
        ['weekday' => 1, 'available' => 1, 'minutes' => 180],
        ['weekday' => 2, 'available' => 1, 'minutes' => 30],
    ],
    $subjects,
    $topics,
    [],
    ['1' => 1, '2' => 2, '3' => 3]
);
$assert($firstTaskTitles === array_column($repeatGeneration['tasks'], 'title_en'), 'Planner generation must be deterministic for the same input.');
$futureRevisionGeneration = PlannerEngine::generateMonth(
    new DateTimeImmutable('2026-08-10'),
    new DateTimeImmutable('2026-08-11'),
    ['daily_minutes' => 180, 'preferred_session_minutes' => 50],
    [
        ['weekday' => 1, 'available' => 1, 'minutes' => 180],
        ['weekday' => 2, 'available' => 1, 'minutes' => 30],
    ],
    $subjects,
    $topics,
    [['topic_id' => 11, 'recent_accuracy' => 0.7, 'mastery_status' => 'learning', 'next_revision_at' => '2026-08-20']],
    []
);
$lsTask = array_values(array_filter($futureRevisionGeneration['tasks'], static fn(array $task): bool => (int)$task['subject_id'] === 1))[0] ?? null;
$assert($lsTask !== null && $lsTask['task_type'] === 'practice', 'A future revision date must not become a revision task early.');
echo "Planner rules tests passed.\n";