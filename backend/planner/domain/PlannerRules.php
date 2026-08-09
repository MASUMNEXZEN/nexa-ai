<?php
declare(strict_types=1);

namespace Nexa\Planner\Domain;

use InvalidArgumentException;

final class PlannerRules
{
    public const DEFAULT_DAILY_MINUTES = 180;
    public const DEFAULT_SESSION_MINUTES = 50;
    public const MIN_FULL_SESSION_MINUTES = 30;
    public const MIN_SUBJECT_TOUCHPOINTS = 3;

    /**
     * Missing capacity means the product uses its explicit self-study baseline.
     * Zero is allowed as an intentional light day; the caller decides whether
     * that weekday is marked available.
     */
    public static function normalizeDailyMinutes(?int $minutes): int
    {
        if ($minutes === null) {
            return self::DEFAULT_DAILY_MINUTES;
        }

        if ($minutes < 0) {
            throw new InvalidArgumentException('Daily study minutes cannot be negative.');
        }

        return $minutes;
    }

    public static function normalizeSessionMinutes(?int $minutes): int
    {
        if ($minutes === null) {
            return self::DEFAULT_SESSION_MINUTES;
        }

        if ($minutes < self::MIN_FULL_SESSION_MINUTES) {
            throw new InvalidArgumentException('A full study session must be at least 30 minutes.');
        }

        return $minutes;
    }

    /**
     * Normalize and validate the subject touchpoints required for a day.
     *
     * @return list<string|int>
     */
    public static function normalizeSubjectIds(array $subjectIds): array
    {
        $normalized = [];

        foreach ($subjectIds as $subjectId) {
            if (is_int($subjectId) || is_string($subjectId) && trim($subjectId) !== '') {
                $key = is_int($subjectId) ? $subjectId : trim($subjectId);
                if (!in_array($key, $normalized, true)) {
                    $normalized[] = $key;
                }
            }
        }

        if (count($normalized) < self::MIN_SUBJECT_TOUCHPOINTS) {
            throw new InvalidArgumentException('A study day must contain at least three distinct subjects.');
        }

        return $normalized;
    }

    /**
     * Allocate a day across distinct subject touchpoints.
     *
     * The allocation is intentionally balanced in this first slice. Official
     * subject weights and learner performance are applied by the application
     * service when it selects the subject order.
     *
     * @return list<array{subject_id:string|int, estimated_minutes:int, coverage_mode:string}>
     */
    public static function allocateSubjectTouchpoints(
        int $dailyMinutes,
        array $subjectIds,
        ?int $preferredSessionMinutes = null
    ): array
    {
        $minutes = self::normalizeDailyMinutes($dailyMinutes);
        $subjects = self::normalizeSubjectIds($subjectIds);
        $sessionMinutes = self::normalizeSessionMinutes($preferredSessionMinutes);
        $count = count($subjects);
        $touchpoints = [];

        if ($minutes === 0) {
            foreach ($subjects as $subjectId) {
                $touchpoints[] = [
                    'subject_id' => $subjectId,
                    'estimated_minutes' => 0,
                    'coverage_mode' => 'light',
                ];
            }
            return $touchpoints;
        }

        $remaining = $minutes;
        foreach ($subjects as $index => $subjectId) {
            $slotsLeft = $count - $index;
            $allocation = min($sessionMinutes, max(0, intdiv($remaining, $slotsLeft)));
            if ($allocation === 0 && $remaining > 0 && $slotsLeft === 1) {
                $allocation = min($sessionMinutes, $remaining);
            }
            $touchpoints[] = [
                'subject_id' => $subjectId,
                'estimated_minutes' => $allocation,
                'coverage_mode' => $allocation >= self::MIN_FULL_SESSION_MINUTES ? 'full' : 'light',
            ];
            $remaining -= $allocation;
        }

        $subjectIndex = 0;
        while ($remaining > 0) {
            $allocation = min($sessionMinutes, $remaining);
            $touchpoints[] = [
                'subject_id' => $subjects[$subjectIndex % $count],
                'estimated_minutes' => $allocation,
                'coverage_mode' => $allocation >= self::MIN_FULL_SESSION_MINUTES ? 'full' : 'light',
            ];
            $remaining -= $allocation;
            $subjectIndex++;
        }

        return $touchpoints;
    }

    /**
     * Validate the persisted profile at the application boundary.
     *
     * @return array{daily_minutes:int, preferred_session_minutes:int}
     */
    public static function normalizeProfile(array $profile): array
    {
        $rawDaily = $profile['daily_minutes'] ?? null;
        $rawSession = $profile['preferred_session_minutes'] ?? null;

        $dailyMinutes = $rawDaily === null || $rawDaily === ''
            ? null
            : filter_var($rawDaily, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($rawDaily !== null && $rawDaily !== '' && $dailyMinutes === false) {
            throw new InvalidArgumentException('Daily study minutes must be a non-negative integer.');
        }

        $sessionMinutes = $rawSession === null || $rawSession === ''
            ? null
            : filter_var($rawSession, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_FULL_SESSION_MINUTES]]);

        if ($rawSession !== null && $rawSession !== '' && $sessionMinutes === false) {
            throw new InvalidArgumentException('Session length must be at least 30 minutes.');
        }

        return [
            'daily_minutes' => self::normalizeDailyMinutes($dailyMinutes === null ? null : (int)$dailyMinutes),
            'preferred_session_minutes' => self::normalizeSessionMinutes($sessionMinutes === null ? null : (int)$sessionMinutes),
        ];
    }
}