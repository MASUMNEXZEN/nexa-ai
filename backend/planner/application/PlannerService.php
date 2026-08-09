<?php
declare(strict_types=1);

namespace Nexa\Planner\Application;

use DateTimeImmutable;
use DateTimeZone;
use Nexa\Planner\Adapters\NexaPlannerRepository;
use Nexa\Planner\Domain\PlannerEngine;
use RuntimeException;

final class PlannerNotReadyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class PlannerService
{
    private const TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly NexaPlannerRepository $repository)
    {
    }

    public function profilePayload(int $userId): array
    {
        $profile = $this->repository->getProfile($userId);
        return [
            'profile' => $profile ? $this->formatProfile($profile) : null,
            'availability' => $profile ? $this->repository->getAvailability($userId) : [],
            'subjects' => $profile ? $this->repository->getSubjects((int)$profile['exam_id']) : [],
            'topics' => $profile ? $this->repository->getTopics((int)$profile['exam_id']) : [],
            'exams' => $this->repository->listActiveExams(),
        ];
    }

    public function generateCurrentMonth(int $userId, bool $force = false, ?int $requestedYear = null, ?int $requestedMonth = null): array
    {
        $profile = $this->requireReadyProfile($userId);
        $today = new DateTimeImmutable('today', new DateTimeZone(self::TIMEZONE));
        if (($requestedYear === null) !== ($requestedMonth === null)) {
            throw new PlannerNotReadyException('invalid_month', 'Provide both calendar year and month.');
        }
        $year = $requestedYear ?? (int)$today->format('Y');
        $month = $requestedMonth ?? (int)$today->format('n');
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new PlannerNotReadyException('invalid_month', 'Use a valid calendar month.');
        }
        $existing = $this->repository->findActiveMonth($userId, (int)$profile['exam_id'], $year, $month);
        if ($existing && !$force) {
            return $this->monthPayload($userId, $existing);
        }

        $targetMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone(self::TIMEZONE));
        $startDate = ($year === (int)$today->format('Y') && $month === (int)$today->format('n'))
            ? $today
            : $targetMonth;
        $endDate = $targetMonth->modify('last day of this month');
        $subjects = $this->repository->getSubjects((int)$profile['exam_id']);
        $topics = $this->repository->getTopics((int)$profile['exam_id']);
        $progress = $this->repository->getTopicProgress($userId, (int)$profile['exam_id']);
        $questions = $this->repository->getPlannerQuestions((int)$profile['exam_id']);
        $availability = $this->repository->getAvailability($userId);
        $preferences = [];
        for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
            $weekKey = $date->format('o-W');
            if (!isset($preferences[$weekKey])) {
                $preferences[$weekKey] = $this->repository->getPreferenceRanks(
                    $userId,
                    (int)$date->format('o'),
                    (int)$date->format('W')
                );
            }
        }

        $generation = PlannerEngine::generateMonth(
            $startDate,
            $endDate,
            $profile,
            $availability,
            $subjects,
            $topics,
            $progress,
            $preferences,
            $questions
        );
        $snapshot = [
            'profile' => $profile,
            'availability' => $availability,
            'subjects' => $subjects,
            'topics' => array_map(static fn(array $topic): array => [
                'id' => $topic['id'],
                'subject_id' => $topic['subject_id'],
                'code' => $topic['code'],
            ], $topics),
            'progress' => $progress,
            'question_count' => count($questions),
            'preferences' => $preferences,
            'range' => [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')],
        ];
        $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $persisted = $this->repository->persistMonth(
            $userId,
            (int)$profile['exam_id'],
            $year,
            $month,
            $generation,
            $snapshotHash
        );
        $monthRow = $this->repository->findActiveMonth($userId, (int)$profile['exam_id'], $year, $month);
        if (!$monthRow || (int)$monthRow['id'] !== $persisted['month_id']) {
            throw new RuntimeException('Generated planner month could not be loaded.');
        }
        return $this->monthPayload($userId, $monthRow);
    }

    public function weekPayload(int $userId, ?string $date): array
    {
        $profile = $this->requireReadyProfile($userId);
        $target = $this->parseDate($date) ?? new DateTimeImmutable('today', new DateTimeZone(self::TIMEZONE));
        $weekStart = $target->modify('monday this week');
        return [
            'week' => [
                'week_start' => $weekStart->format('Y-m-d'),
                'week_end' => $weekStart->modify('+6 days')->format('Y-m-d'),
                'calendar_year' => (int)$weekStart->format('o'),
                'calendar_week' => (int)$weekStart->format('W'),
            ],
            'preferences' => $this->repository->getPreferenceDetails(
                $userId,
                (int)$weekStart->format('o'),
                (int)$weekStart->format('W')
            ),
            'tasks' => $this->repository->findWeekTasks($userId, $weekStart->format('Y-m-d')),
            'exam' => [
                'code' => $profile['exam_code'],
                'name' => $profile['exam_name'],
            ],
        ];
    }

    public function dayPayload(int $userId, ?string $date): array
    {
        $profile = $this->requireReadyProfile($userId);
        $target = $this->parseDate($date) ?? new DateTimeImmutable('today', new DateTimeZone(self::TIMEZONE));
        return [
            'date' => $target->format('Y-m-d'),
            'tasks' => $this->repository->findDayTasks($userId, $target->format('Y-m-d')),
            'exam' => [
                'code' => $profile['exam_code'],
                'name' => $profile['exam_name'],
            ],
        ];
    }

    private function requireReadyProfile(int $userId): array
    {
        $profile = $this->repository->getProfile($userId);
        if (!$profile) {
            throw new PlannerNotReadyException('profile_required', 'Complete your study planner profile first.');
        }
        if (empty($profile['onboarding_completed_at'])) {
            throw new PlannerNotReadyException('onboarding_required', 'Complete planner onboarding before generating a plan.');
        }
        if (empty($profile['diagnostic_completed_at'])) {
            throw new PlannerNotReadyException('diagnostic_required', 'Complete the diagnostic assessment before generating a personalized plan.');
        }
        return $profile;
    }

    private function monthPayload(int $userId, array $month): array
    {
        $tasks = $this->repository->findMonthTasks($userId, (int)$month['id']);
        return [
            'month' => [
                'id' => (int)$month['id'],
                'calendar_year' => (int)$month['calendar_year'],
                'calendar_month' => (int)$month['calendar_month'],
                'generated_at' => $month['generated_at'],
                'generation_version' => $month['generation_version'],
                'status' => $month['status'],
                'summary' => $month['summary'],
            ],
            'tasks' => $tasks,
        ];
    }

    private function formatProfile(array $profile): array
    {
        return [
            'user_id' => (int)$profile['user_id'],
            'exam_id' => (int)$profile['exam_id'],
            'exam_code' => $profile['exam_code'],
            'exam_name' => $profile['exam_name'],
            'daily_minutes' => $profile['daily_minutes'] === null ? null : (int)$profile['daily_minutes'],
            'effective_daily_minutes' => $profile['daily_minutes'] === null ? 180 : (int)$profile['daily_minutes'],
            'preferred_session_minutes' => (int)$profile['preferred_session_minutes'],
            'preferred_time_block' => $profile['preferred_time_block'],
            'current_level' => $profile['current_level'],
            'onboarding_completed' => !empty($profile['onboarding_completed_at']),
            'diagnostic_completed' => !empty($profile['diagnostic_completed_at']),
            'onboarding_completed_at' => $profile['onboarding_completed_at'],
            'diagnostic_completed_at' => $profile['diagnostic_completed_at'],
        ];
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new PlannerNotReadyException('invalid_date', 'Use a valid calendar date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new PlannerNotReadyException('invalid_date', 'Use a valid calendar date.');
        }
        return $date;
    }
}
