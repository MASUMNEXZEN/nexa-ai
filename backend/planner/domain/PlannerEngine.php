<?php
declare(strict_types=1);

namespace Nexa\Planner\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Deterministic planner generation. It consumes normalized host data and does
 * not know about HTTP, PDO, authentication, branding, or a particular exam.
 */
final class PlannerEngine
{
    public const GENERATION_VERSION = 'planner-v1';

    /**
     * @return array{generation_version:string, summary:array<string,mixed>, tasks:list<array<string,mixed>>}
     */
    public static function generateMonth(
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        array $profile,
        array $availability,
        array $subjects,
        array $topics,
        array $progress,
        array $preferences,
        array $questions = []
    ): array {
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('Planner month range is invalid.');
        }
        if (count($subjects) < PlannerRules::MIN_SUBJECT_TOUCHPOINTS) {
            throw new InvalidArgumentException('At least three active subjects are required.');
        }

        $normalizedProfile = PlannerRules::normalizeProfile($profile);
        $availabilityByDay = self::normalizeAvailability($availability, $normalizedProfile['daily_minutes']);
        $topicsBySubject = self::groupTopicsBySubject($topics);
        $progressByTopic = self::indexById($progress, 'topic_id');
        $subjectOrder = array_values($subjects);
        $subjectsById = [];
        foreach ($subjectOrder as $subject) {
            $subjectsById[(int)$subject['id']] = $subject;
        }
        $tasks = [];
        $subjectTotals = [];
        $questionsByTopic = self::groupQuestionsByKey($questions, 'topic_id');
        $questionsBySubject = self::groupQuestionsByKey($questions, 'subject_id');
        $questionCursor = [];
        $lightDays = 0;
        $activeDays = 0;
        $topicCursor = [];

        for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
            $weekday = (int)$date->format('w');
            $day = $availabilityByDay[$weekday] ?? ['available' => false, 'minutes' => 0];
            if (!$day['available']) {
                continue;
            }

            $activeDays++;
            $dailyMinutes = max(0, (int)$day['minutes']);
            $rankedSubjects = self::rankSubjects(
                $subjectOrder,
                $topicsBySubject,
                $progressByTopic,
                self::preferencesForDate($preferences, $date),
                $date
            );
            $selectedSubjects = self::selectDailySubjects($rankedSubjects, $activeDays);
            $touchpoints = PlannerRules::allocateSubjectTouchpoints(
                $dailyMinutes,
                array_column($selectedSubjects, 'id'),
                $normalizedProfile['preferred_session_minutes']
            );

            $isLight = false;
            foreach ($touchpoints as $position => $touchpoint) {
                $subject = $subjectsById[(int)$touchpoint['subject_id']] ?? null;
                if ($subject === null) {
                    continue;
                }
                $subjectId = (int)$subject['id'];
                $topic = self::chooseTopic(
                    $topicsBySubject[$subjectId] ?? [],
                    $progressByTopic,
                    $date,
                    $topicCursor[$subjectId] ?? 0
                );
                $topicCursor[$subjectId] = ($topicCursor[$subjectId] ?? 0) + 1;
                $taskType = self::taskType($topic, $progressByTopic, $date);
                $topicQuestions = $topic === null ? [] : ($questionsByTopic[(int)$topic['id']] ?? []);
                $subjectQuestions = $questionsBySubject[$subjectId] ?? [];
                $questionKey = $topicQuestions !== []
                    ? 'topic:' . (int)$topic['id']
                    : 'subject:' . $subjectId;
                $questionIndex = $questionCursor[$questionKey] ?? 0;
                $question = self::chooseQuestion($topicQuestions, $subjectQuestions, $questionIndex);
                if ($question !== null) {
                    $questionCursor[$questionKey] = $questionIndex + 1;
                    if ($taskType === 'practice' && $question['question_year'] !== null) {
                        $taskType = 'pyq';
                    }
                }
                $title = self::taskTitle($taskType, $subject, $topic);
                $minutes = (int)$touchpoint['estimated_minutes'];
                $isLight = $isLight || $touchpoint['coverage_mode'] === 'light';
                $subjectTotals[$subject['code']] = ($subjectTotals[$subject['code']] ?? 0) + $minutes;

                $tasks[] = [
                    'scheduled_date' => $date->format('Y-m-d'),
                    'task_type' => $taskType,
                    'subject_id' => $subjectId,
                    'unit_id' => $topic ? (int)$topic['unit_id'] : null,
                    'topic_id' => $topic ? (int)$topic['id'] : null,
                    'question_id' => $question === null ? null : (int)$question['id'],
                    'title_en' => $title,
                    'estimated_minutes' => $minutes,
                    'display_order' => $position + 1,
                    'coverage_mode' => $touchpoint['coverage_mode'],
                    'subject_code' => (string)$subject['code'],
                    'subject_name_en' => (string)$subject['name_en'],
                    'topic_code' => $topic ? (string)$topic['code'] : null,
                    'topic_title_en' => $topic ? (string)$topic['title_en'] : null,
                ];
            }

            if ($isLight) {
                $lightDays++;
            }
        }

        $summary = [
            'active_days' => $activeDays,
            'total_tasks' => count($tasks),
            'total_minutes' => array_sum(array_column($tasks, 'estimated_minutes')),
            'light_coverage_days' => $lightDays,
            'subject_minutes' => $subjectTotals,
            'topic_count' => count($topics),
            'generated_from' => $startDate->format('Y-m-d'),
            'generated_through' => $endDate->format('Y-m-d'),
        ];

        return [
            'generation_version' => self::GENERATION_VERSION,
            'summary' => $summary,
            'tasks' => $tasks,
        ];
    }

    /** @return array<int,array{available:bool,minutes:int}> */
    private static function normalizeAvailability(array $availability, int $defaultMinutes): array
    {
        if ($availability === []) {
            $defaults = [];
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                $defaults[$weekday] = ['available' => true, 'minutes' => $defaultMinutes];
            }
            return $defaults;
        }

        $normalized = [];
        foreach ($availability as $row) {
            $weekday = (int)($row['weekday'] ?? -1);
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }
            $minutes = array_key_exists('minutes', $row)
                ? max(0, (int)$row['minutes'])
                : $defaultMinutes;
            $normalized[$weekday] = [
                'available' => (bool)($row['available'] ?? false),
                'minutes' => $minutes,
            ];
        }
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $normalized[$weekday] ??= ['available' => false, 'minutes' => 0];
        }
        return $normalized;
    }

    /** @return array<int,list<array<string,mixed>>> */
    private static function groupTopicsBySubject(array $topics): array
    {
        $grouped = [];
        foreach ($topics as $topic) {
            $subjectId = (int)($topic['subject_id'] ?? 0);
            if ($subjectId < 1) {
                continue;
            }
            $grouped[$subjectId][] = $topic;
        }
        return $grouped;
    }

    /** @return array<int,array<string,mixed>> */
    private static function indexById(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int)($row[$key] ?? 0);
            if ($id > 0) {
                $indexed[$id] = $row;
            }
        }
        return $indexed;
    }

    /** @return list<array<string,mixed>> */
    private static function preferencesForDate(array $preferences, DateTimeImmutable $date): array
    {
        $weekKey = $date->format('o-W');
        if (isset($preferences[$weekKey]) && is_array($preferences[$weekKey])) {
            return $preferences[$weekKey];
        }
        return $preferences;
    }
    /** @param list<array<string,mixed>> $rankedSubjects */
    private static function selectDailySubjects(array $rankedSubjects, int $activeDay): array
    {
        $required = PlannerRules::MIN_SUBJECT_TOUCHPOINTS;
        if (count($rankedSubjects) <= $required) {
            return array_slice($rankedSubjects, 0, $required);
        }

        $primaryCount = min(2, $required - 1);
        $selected = array_slice($rankedSubjects, 0, $primaryCount);
        $rotating = array_slice($rankedSubjects, $primaryCount);
        $selected[] = $rotating[($activeDay - 1) % count($rotating)];

        return $selected;
    }
    private static function rankSubjects(
        array $subjects,
        array $topicsBySubject,
        array $progressByTopic,
        array $preferences,
        DateTimeImmutable $date
    ): array {
        $maxWeight = max(1.0, ...array_map(static fn(array $subject): float => (float)$subject['official_weight_percent'], $subjects));
        $scored = [];
        foreach ($subjects as $subject) {
            $subjectId = (int)$subject['id'];
            $subjectTopics = $topicsBySubject[$subjectId] ?? [];
            $weakness = 0.5;
            $dueRevision = 0.0;
            if ($subjectTopics !== []) {
                $weaknessValues = [];
                foreach ($subjectTopics as $topic) {
                    $topicProgress = $progressByTopic[(int)$topic['id']] ?? null;
                    if (!$topicProgress) {
                        $weaknessValues[] = 0.5;
                        continue;
                    }
                    if (($topicProgress['mastery_status'] ?? '') === 'mastered') {
                        $weaknessValues[] = 0.0;
                    } else {
                        $weaknessValues[] = max(0.0, 1.0 - (float)($topicProgress['recent_accuracy'] ?? 0));
                    }
                    $nextRevision = (string)($topicProgress['next_revision_at'] ?? '');
                    if (($topicProgress['mastery_status'] ?? '') === 'revision' && ($nextRevision === '' || self::isRevisionDue($nextRevision, $date))) {
                        $dueRevision = 1.0;
                    }
                }
                $weakness = max($weaknessValues);
            }
            $rank = (int)($preferences[(string)$subjectId] ?? 0);
            $preference = $rank > 0 ? max(0.0, 1.0 - (($rank - 1) / max(1, count($subjects)))) : 0.0;
            $scored[] = [
                'subject' => $subject,
                'score' => (((float)$subject['official_weight_percent'] / $maxWeight) * 0.50)
                    + ($weakness * 0.25)
                    + ($dueRevision * 0.15)
                    + ($preference * 0.07),
            ];
        }
        usort($scored, static function (array $left, array $right): int {
            $scoreCompare = $right['score'] <=> $left['score'];
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            $orderCompare = ((int)$left['subject']['display_order']) <=> ((int)$right['subject']['display_order']);
            if ($orderCompare !== 0) {
                return $orderCompare;
            }
            return ((int)$left['subject']['id']) <=> ((int)$right['subject']['id']);
        });
        return array_column($scored, 'subject');
    }

    private static function chooseTopic(array $topics, array $progressByTopic, DateTimeImmutable $date, int $cursor): ?array
    {
        if ($topics === []) {
            return null;
        }
        usort($topics, static function (array $left, array $right) use ($progressByTopic, $date): int {
            $leftScore = self::topicScore($left, $progressByTopic[(int)$left['id']] ?? null, $date);
            $rightScore = self::topicScore($right, $progressByTopic[(int)$right['id']] ?? null, $date);
            $scoreCompare = $rightScore <=> $leftScore;
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            return strcmp((string)$left['code'], (string)$right['code']);
        });
        return $topics[$cursor % count($topics)];
    }

    private static function topicScore(array $topic, ?array $progress, DateTimeImmutable $date): float
    {
        if ($progress === null) {
            return 1.0;
        }
        $score = max(0.0, 1.0 - (float)($progress['recent_accuracy'] ?? 0));
        if (($progress['mastery_status'] ?? '') === 'mastered') {
            $score -= 0.75;
        }
        $nextRevision = (string)($progress['next_revision_at'] ?? '');
        if ($nextRevision !== '' && self::isRevisionDue($nextRevision, $date)) {
            $score += 1.0;
        }
        if (str_contains(strtoupper((string)($topic['scope_tag'] ?? '')), 'CORE')) {
            $score += 0.25;
        }
        return $score;
    }

    private static function isRevisionDue(string $nextRevision, DateTimeImmutable $date): bool
    {
        $revisionDate = substr($nextRevision, 0, 10);
        return $revisionDate !== '' && $revisionDate <= $date->format('Y-m-d');
    }
    /** @return array<int,list<array<string,mixed>>> */
    private static function groupQuestionsByKey(array $questions, string $key): array
    {
        $grouped = [];
        foreach ($questions as $question) {
            $id = (int)($question[$key] ?? 0);
            if ($id > 0 && (int)($question['id'] ?? 0) > 0) {
                $grouped[$id][] = $question;
            }
        }
        return $grouped;
    }

    private static function chooseQuestion(array $topicQuestions, array $subjectQuestions, int $cursor): ?array
    {
        $available = $topicQuestions !== [] ? $topicQuestions : $subjectQuestions;
        if ($available === []) {
            return null;
        }
        return $available[$cursor % count($available)];
    }

    private static function taskType(?array $topic, array $progressByTopic, DateTimeImmutable $date): string
    {
        if ($topic === null) {
            return 'practice';
        }
        $progress = $progressByTopic[(int)$topic['id']] ?? null;
        if ($progress === null || ($progress['mastery_status'] ?? 'not_started') === 'not_started') {
            return 'learn';
        }
        $nextRevision = (string)($progress['next_revision_at'] ?? '');
        if (($progress['mastery_status'] ?? '') === 'revision' || ($nextRevision !== '' && self::isRevisionDue($nextRevision, $date))) {
            return 'revision';
        }
        return 'practice';
    }

    private static function taskTitle(string $taskType, array $subject, ?array $topic): string
    {
        $prefixes = [
            'learn' => 'Learn',
            'practice' => 'Practice',
            'pyq' => 'Solve PYQ',
            'revision' => 'Revise',
            'recovery' => 'Recover',
        ];
        $prefix = $prefixes[$taskType] ?? ucfirst($taskType);
        $subjectName = (string)$subject['name_en'];
        $topicName = $topic ? (string)$topic['title_en'] : 'core questions';
        return $prefix . ': ' . $subjectName . ' — ' . $topicName;
    }
}