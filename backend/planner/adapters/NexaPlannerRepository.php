<?php
declare(strict_types=1);

namespace Nexa\Planner\Adapters;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class NexaPlannerRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findActiveExam(string $examCode): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, display_name, syllabus_version, active
             FROM exams WHERE code = ? AND active = 1 LIMIT 1'
        );
        $statement->execute([$examCode]);
        $exam = $statement->fetch();
        return $exam ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listActiveExams(): array
    {
        return $this->db->query(
            'SELECT id, code, display_name, syllabus_version
             FROM exams WHERE active = 1 ORDER BY display_name ASC'
        )->fetchAll();
    }

    public function getProfile(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT p.user_id, p.exam_id, p.daily_minutes, p.preferred_session_minutes,
                    p.preferred_time_block, p.current_level, p.onboarding_completed_at,
                    p.diagnostic_completed_at, p.active, e.code AS exam_code,
                    e.display_name AS exam_name
             FROM planner_profiles p
             JOIN exams e ON e.id = p.exam_id
             WHERE p.user_id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $profile = $statement->fetch();
        return $profile ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function getAvailability(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT weekday, available, minutes
             FROM planner_availability WHERE user_id = ? ORDER BY weekday ASC'
        );
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function getSubjects(int $examId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name_en, official_weight_percent, display_order
             FROM exam_subjects
             WHERE exam_id = ? AND active = 1
             ORDER BY display_order ASC, id ASC'
        );
        $statement->execute([$examId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function getTopics(int $examId): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.unit_id, u.subject_id, t.code, t.title_en, t.scope_tag,
                    t.estimated_minutes
             FROM syllabus_topics t
             JOIN syllabus_units u ON u.id = t.unit_id AND u.active = 1
             WHERE u.exam_id = ? AND t.active = 1
             ORDER BY u.subject_id ASC, u.code ASC, t.code ASC'
        );
        $statement->execute([$examId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function getPlannerQuestions(int $examId, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->db->prepare(
            'SELECT q.id, q.subject_id, q.unit_id, q.topic_id, q.question_year,
                    q.category, q.difficulty, q.prompt_en
             FROM questions q
             JOIN exam_subjects s ON s.id = q.subject_id AND s.exam_id = q.exam_id
             WHERE q.exam_id = ? AND q.publication_state = \'published\'
               AND q.authoritative = 1
               AND q.correct_option_key IN (\'A\', \'B\', \'C\', \'D\')
               AND (SELECT COUNT(*) FROM question_options qo
                    WHERE qo.question_id = q.id
                      AND qo.option_key IN (\'A\', \'B\', \'C\', \'D\')) = 4
             ORDER BY CASE WHEN q.question_year IS NULL THEN 1 ELSE 0 END ASC,
                      q.question_year ASC, q.id ASC
             LIMIT ' . $limit
        );
        $statement->execute([$examId]);
        return $statement->fetchAll();
    }
    public function getTopicProgress(int $userId, int $examId): array
    {
        $statement = $this->db->prepare(
            'SELECT tp.topic_id, tp.attempts, tp.correct_attempts, tp.recent_accuracy,
                    tp.mastery_status, tp.last_studied_at, tp.next_revision_at
             FROM topic_progress tp
             JOIN syllabus_topics t ON t.id = tp.topic_id
             JOIN syllabus_units u ON u.id = t.unit_id
             WHERE tp.user_id = ? AND u.exam_id = ?'
        );
        $statement->execute([$userId, $examId]);
        return $statement->fetchAll();
    }

    /** @return array<string,int> subject id => preference rank */
    public function getPreferenceRanks(int $userId, int $calendarYear, int $calendarWeek): array
    {
        $statement = $this->db->prepare(
            'SELECT subject_id, preference_rank
             FROM weekly_subject_preferences
             WHERE user_id = ? AND calendar_year = ? AND calendar_week = ?
             ORDER BY preference_rank ASC, subject_id ASC'
        );
        $statement->execute([$userId, $calendarYear, $calendarWeek]);
        $ranks = [];
        foreach ($statement->fetchAll() as $row) {
            $ranks[(string)(int)$row['subject_id']] = (int)$row['preference_rank'];
        }
        return $ranks;
    }

    /** @return list<array<string,mixed>> */
    public function getPreferenceDetails(int $userId, int $calendarYear, int $calendarWeek): array
    {
        $statement = $this->db->prepare(
            'SELECT p.subject_id, p.preference_rank, s.code, s.name_en
             FROM weekly_subject_preferences p
             JOIN exam_subjects s ON s.id = p.subject_id
             WHERE p.user_id = ? AND p.calendar_year = ? AND p.calendar_week = ?
             ORDER BY p.preference_rank ASC, s.display_order ASC'
        );
        $statement->execute([$userId, $calendarYear, $calendarWeek]);
        return $statement->fetchAll();
    }

    public function saveProfile(
        int $userId,
        int $examId,
        ?int $dailyMinutes,
        int $sessionMinutes,
        ?string $timeBlock,
        string $currentLevel,
        bool $completeOnboarding,
        ?array $availability
    ): array {
        $this->db->beginTransaction();
        try {
            $completedAt = $completeOnboarding ? gmdate('Y-m-d H:i:s') : null;
            $this->db->prepare(
                'INSERT INTO planner_profiles
                    (user_id, exam_id, daily_minutes, preferred_session_minutes,
                     preferred_time_block, current_level, onboarding_completed_at, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 ON CONFLICT(user_id) DO UPDATE SET
                    exam_id = excluded.exam_id,
                    diagnostic_completed_at = CASE
                        WHEN planner_profiles.exam_id <> excluded.exam_id THEN NULL
                        ELSE planner_profiles.diagnostic_completed_at
                    END,
                    daily_minutes = excluded.daily_minutes,
                    preferred_session_minutes = excluded.preferred_session_minutes,
                    preferred_time_block = excluded.preferred_time_block,
                    current_level = excluded.current_level,
                    onboarding_completed_at = CASE
                        WHEN excluded.onboarding_completed_at IS NOT NULL
                            THEN excluded.onboarding_completed_at
                        ELSE planner_profiles.onboarding_completed_at
                    END,
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP'
            )->execute([
                $userId,
                $examId,
                $dailyMinutes,
                $sessionMinutes,
                $timeBlock,
                $currentLevel,
                $completedAt,
            ]);

            if ($availability !== null) {
                $this->db->prepare('DELETE FROM planner_availability WHERE user_id = ?')->execute([$userId]);
                $insert = $this->db->prepare(
                    'INSERT INTO planner_availability (user_id, weekday, available, minutes)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($availability as $row) {
                    $insert->execute([
                        $userId,
                        (int)$row['weekday'],
                        (int)$row['available'],
                        (int)$row['minutes'],
                    ]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $profile = $this->getProfile($userId);
        if (!$profile) {
            throw new RuntimeException('Planner profile could not be loaded after saving.');
        }
        return $profile;
    }

    /** @param list<int> $subjectIds */
    public function replaceWeeklyPreferences(
        int $userId,
        int $calendarYear,
        int $calendarWeek,
        array $subjectIds
    ): void {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'DELETE FROM weekly_subject_preferences
                 WHERE user_id = ? AND calendar_year = ? AND calendar_week = ?'
            )->execute([$userId, $calendarYear, $calendarWeek]);
            $insert = $this->db->prepare(
                'INSERT INTO weekly_subject_preferences
                    (user_id, calendar_year, calendar_week, subject_id, preference_rank, selected_by_student)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            foreach (array_values($subjectIds) as $index => $subjectId) {
                $insert->execute([$userId, $calendarYear, $calendarWeek, $subjectId, $index + 1]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{month_id:int,generation_version:string} */
    /** @return array{month_id:int,generation_version:string} */
    public function persistMonth(
        int $userId,
        int $examId,
        int $calendarYear,
        int $calendarMonth,
        array $generation,
        string $snapshotHash
    ): array {
        $generationVersion = $generation['generation_version'] . ':' . substr($snapshotHash, 0, 16)
            . ':' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $this->db->beginTransaction();
        try {
            $previousStatement = $this->db->prepare(
                'SELECT scheduled_date, task_type, subject_id, unit_id, topic_id,
                        question_id, title_en, estimated_minutes, display_order, origin,
                        status, completed_at, completion_note
                 FROM planner_tasks
                 WHERE user_id = ? AND month_id IN (
                     SELECT id FROM planner_months
                     WHERE user_id = ? AND exam_id = ? AND calendar_year = ?
                       AND calendar_month = ? AND status = \'active\'
                 )
                   AND (origin = \'manual\' OR status IN (\'in_progress\', \'completed\', \'skipped\'))
                 ORDER BY scheduled_date ASC, display_order ASC, id ASC'
            );
            $previousStatement->execute([$userId, $userId, $examId, $calendarYear, $calendarMonth]);
            $preservedTasks = [];
            $manualTasks = [];
            foreach ($previousStatement->fetchAll() as $previousTask) {
                if (($previousTask['origin'] ?? 'generated') === 'manual') {
                    $manualTasks[] = $previousTask;
                    continue;
                }
                $key = implode('|', [
                    (string)$previousTask['scheduled_date'],
                    (string)$previousTask['task_type'],
                    (int)$previousTask['subject_id'],
                    $previousTask['unit_id'] === null ? 'null' : (int)$previousTask['unit_id'],
                    $previousTask['topic_id'] === null ? 'null' : (int)$previousTask['topic_id'],
                    (int)$previousTask['display_order'],
                ]);
                $preservedTasks[$key][] = $previousTask;
            }
            $this->db->prepare(
                'UPDATE planner_months SET status = \'superseded\'
                 WHERE user_id = ? AND exam_id = ? AND calendar_year = ?
                   AND calendar_month = ? AND status = \'active\''
            )->execute([$userId, $examId, $calendarYear, $calendarMonth]);

            $monthInsert = $this->db->prepare(
                'INSERT INTO planner_months
                    (user_id, exam_id, calendar_year, calendar_month,
                     generation_version, status, summary_json)
                 VALUES (?, ?, ?, ?, ?, \'active\', ?)'
            );
            $monthInsert->execute([
                $userId,
                $examId,
                $calendarYear,
                $calendarMonth,
                $generationVersion,
                json_encode($generation['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $monthId = (int)$this->db->lastInsertId();

            $weekIds = [];
            $weekInsert = $this->db->prepare(
                'INSERT INTO planner_weeks
                    (month_id, user_id, week_start, week_end, generation_version, status)
                 VALUES (?, ?, ?, ?, ?, \'active\')'
            );
            $taskInsert = $this->db->prepare(
                'INSERT INTO planner_tasks
                    (user_id, month_id, week_id, scheduled_date, task_type,
                     subject_id, unit_id, topic_id, question_id, title_en,
                     estimated_minutes, display_order, origin, status, completed_at, completion_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($generation['tasks'] as $task) {
                $date = new DateTimeImmutable((string)$task['scheduled_date']);
                $weekStart = $date->modify('monday this week')->format('Y-m-d');
                $weekEnd = $date->modify('monday this week')->modify('+6 days')->format('Y-m-d');
                $weekKey = $weekStart;
                if (!isset($weekIds[$weekKey])) {
                    $weekInsert->execute([$monthId, $userId, $weekStart, $weekEnd, $generationVersion]);
                    $weekIds[$weekKey] = (int)$this->db->lastInsertId();
                }
                $preservationKey = implode('|', [
                    (string)$task['scheduled_date'],
                    (string)$task['task_type'],
                    (int)$task['subject_id'],
                    $task['unit_id'] === null ? 'null' : (int)$task['unit_id'],
                    $task['topic_id'] === null ? 'null' : (int)$task['topic_id'],
                    (int)$task['display_order'],
                ]);
                $preserved = $preservedTasks[$preservationKey] ?? [];
                $prior = $preserved === [] ? null : array_shift($preserved);
                $preservedTasks[$preservationKey] = $preserved;
                $taskInsert->execute([
                    $userId,
                    $monthId,
                    $weekIds[$weekKey],
                    $task['scheduled_date'],
                    $task['task_type'],
                    $task['subject_id'],
                    $task['unit_id'],
                    $task['topic_id'],
                    $task['question_id'],
                    $task['title_en'],
                    $task['estimated_minutes'],
                    $task['display_order'],
                    'generated',
                    $prior['status'] ?? 'planned',
                    $prior['completed_at'] ?? null,
                    $prior['completion_note'] ?? null,
                ]);
            }

            foreach ($manualTasks as $manualIndex => $manualTask) {
                $date = new DateTimeImmutable((string)$manualTask['scheduled_date']);
                $weekStart = $date->modify('monday this week')->format('Y-m-d');
                $weekEnd = $date->modify('monday this week')->modify('+6 days')->format('Y-m-d');
                if (!isset($weekIds[$weekStart])) {
                    $weekInsert->execute([$monthId, $userId, $weekStart, $weekEnd, $generationVersion]);
                    $weekIds[$weekStart] = (int)$this->db->lastInsertId();
                }
                $taskInsert->execute([
                    $userId,
                    $monthId,
                    $weekIds[$weekStart],
                    $manualTask['scheduled_date'],
                    $manualTask['task_type'],
                    $manualTask['subject_id'],
                    $manualTask['unit_id'],
                    $manualTask['topic_id'],
                    $manualTask['question_id'],
                    $manualTask['title_en'],
                    $manualTask['estimated_minutes'],
                    1000 + (int)$manualIndex,
                    'manual',
                    $manualTask['status'],
                    $manualTask['completed_at'],
                    $manualTask['completion_note'],
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return ['month_id' => $monthId, 'generation_version' => $generationVersion];
    }
    public function findActiveMonth(int $userId, int $examId, int $year, int $month): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, calendar_year, calendar_month, generated_at,
                    generation_version, status, summary_json
             FROM planner_months
             WHERE user_id = ? AND exam_id = ? AND calendar_year = ?
               AND calendar_month = ? AND status = \'active\'
             ORDER BY generated_at DESC, id DESC LIMIT 1'
        );
        $statement->execute([$userId, $examId, $year, $month]);
        $monthRow = $statement->fetch();
        if (!$monthRow) {
            return null;
        }
        $monthRow['summary'] = json_decode((string)$monthRow['summary_json'], true) ?: [];
        unset($monthRow['summary_json']);
        return $monthRow;
    }

    /** @return list<array<string,mixed>> */
    public function findMonthTasks(int $userId, int $monthId): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.scheduled_date, t.task_type, t.subject_id,
                    t.origin, t.unit_id, t.topic_id, t.question_id, t.title_en,
                    t.estimated_minutes, t.display_order, t.status,
                    t.completed_at, t.completion_note,
                    q.question_year, q.category AS question_category, q.prompt_en AS question_prompt_en,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    st.code AS topic_code, st.title_en AS topic_title_en
             FROM planner_tasks t
             JOIN exam_subjects s ON s.id = t.subject_id
             LEFT JOIN syllabus_topics st ON st.id = t.topic_id
             LEFT JOIN questions q ON q.id = t.question_id
             WHERE t.user_id = ? AND t.month_id = ?
             ORDER BY t.scheduled_date ASC, t.display_order ASC, t.id ASC'
        );
        $statement->execute([$userId, $monthId]);
        return $statement->fetchAll();
    }

    public function findDayTasks(int $userId, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.scheduled_date, t.task_type, t.subject_id,
                    t.origin, t.unit_id, t.topic_id, t.question_id, t.title_en,
                    t.estimated_minutes, t.display_order, t.status,
                    t.completed_at, t.completion_note,
                    q.question_year, q.category AS question_category, q.prompt_en AS question_prompt_en,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    st.code AS topic_code, st.title_en AS topic_title_en
             FROM planner_tasks t
             JOIN planner_months m ON m.id = t.month_id AND m.status = \'active\'
             JOIN exam_subjects s ON s.id = t.subject_id
             LEFT JOIN syllabus_topics st ON st.id = t.topic_id
             LEFT JOIN questions q ON q.id = t.question_id
             WHERE t.user_id = ? AND t.scheduled_date = ?
             ORDER BY t.display_order ASC, t.id ASC'
        );
        $statement->execute([$userId, $date]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function findWeekTasks(int $userId, string $weekStart): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.scheduled_date, t.task_type, t.subject_id,
                    t.origin, t.unit_id, t.topic_id, t.question_id, t.title_en,
                    t.estimated_minutes, t.display_order, t.status,
                    t.completed_at, t.completion_note,
                    q.question_year, q.category AS question_category, q.prompt_en AS question_prompt_en,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    st.code AS topic_code, st.title_en AS topic_title_en
             FROM planner_tasks t
             JOIN planner_weeks w ON w.id = t.week_id AND w.status = \'active\'
             JOIN exam_subjects s ON s.id = t.subject_id
             LEFT JOIN syllabus_topics st ON st.id = t.topic_id
             LEFT JOIN questions q ON q.id = t.question_id
             WHERE t.user_id = ? AND w.week_start = ?
             ORDER BY t.scheduled_date ASC, t.display_order ASC, t.id ASC'
        );
        $statement->execute([$userId, $weekStart]);
        return $statement->fetchAll();
    }
    /** @param list<array<string,mixed>> $tasks */
    public function createManualTasks(int $userId, int $examId, array $tasks): array
    {
        if ($tasks === [] || count($tasks) > 21) {
            throw new RuntimeException('Add between one and twenty-one manual study sessions.');
        }
        $this->db->beginTransaction();
        $createdIds = [];
        $monthRows = [];
        $weekIds = [];
        $nextOrders = [];
        try {
            $monthStatement = $this->db->prepare(
                'SELECT id, generation_version
                 FROM planner_months
                 WHERE user_id = ? AND exam_id = ? AND calendar_year = ?
                   AND calendar_month = ? AND status = \'active\'
                 ORDER BY generated_at DESC, id DESC LIMIT 1'
            );
            $weekStatement = $this->db->prepare(
                'SELECT id FROM planner_weeks
                 WHERE user_id = ? AND month_id = ? AND week_start = ? AND status = \'active\'
                 ORDER BY id DESC LIMIT 1'
            );
            $weekInsert = $this->db->prepare(
                'INSERT INTO planner_weeks
                    (month_id, user_id, week_start, week_end, generation_version, status)
                 VALUES (?, ?, ?, ?, ?, \'active\')'
            );
            $orderStatement = $this->db->prepare(
                'SELECT COALESCE(MAX(display_order), 0) + 1
                 FROM planner_tasks WHERE user_id = ? AND month_id = ? AND scheduled_date = ?'
            );
            $taskInsert = $this->db->prepare(
                'INSERT INTO planner_tasks
                    (user_id, month_id, week_id, scheduled_date, task_type,
                     subject_id, unit_id, topic_id, question_id, title_en,
                     estimated_minutes, display_order, origin, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, \'manual\', \'planned\')'
            );
            foreach ($tasks as $task) {
                $date = new DateTimeImmutable((string)$task['scheduled_date']);
                $monthKey = $date->format('Y-m');
                if (!isset($monthRows[$monthKey])) {
                    $monthStatement->execute([
                        $userId,
                        $examId,
                        (int)$date->format('Y'),
                        (int)$date->format('n'),
                    ]);
                    $monthRows[$monthKey] = $monthStatement->fetch() ?: null;
                }
                $month = $monthRows[$monthKey];
                if (!$month) {
                    throw new RuntimeException('Generate the target month before adding a manual session.');
                }
                $weekStart = $date->modify('monday this week')->format('Y-m-d');
                $weekKey = (int)$month['id'] . ':' . $weekStart;
                if (!isset($weekIds[$weekKey])) {
                    $weekStatement->execute([$userId, (int)$month['id'], $weekStart]);
                    $weekId = $weekStatement->fetchColumn();
                    if ($weekId === false) {
                        $weekInsert->execute([
                            (int)$month['id'],
                            $userId,
                            $weekStart,
                            $date->modify('monday this week')->modify('+6 days')->format('Y-m-d'),
                            $month['generation_version'],
                        ]);
                        $weekId = $this->db->lastInsertId();
                    }
                    $weekIds[$weekKey] = (int)$weekId;
                }
                $orderKey = (int)$month['id'] . ':' . $task['scheduled_date'];
                if (!isset($nextOrders[$orderKey])) {
                    $orderStatement->execute([$userId, (int)$month['id'], $task['scheduled_date']]);
                    $nextOrders[$orderKey] = (int)$orderStatement->fetchColumn();
                }
                $displayOrder = $nextOrders[$orderKey]++;
                $taskInsert->execute([
                    $userId,
                    (int)$month['id'],
                    $weekIds[$weekKey],
                    $task['scheduled_date'],
                    $task['task_type'],
                    (int)$task['subject_id'],
                    $task['unit_id'] === null ? null : (int)$task['unit_id'],
                    $task['topic_id'] === null ? null : (int)$task['topic_id'],
                    $task['title_en'],
                    (int)$task['estimated_minutes'],
                    $displayOrder,
                ]);
                $taskId = (int)$this->db->lastInsertId();
                $this->recordTaskEvent($userId, $taskId, 'manual_task_created', [
                    'scheduled_date' => $task['scheduled_date'],
                    'scope' => $task['scope'],
                ]);
                $createdIds[] = $taskId;
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $created = [];
        foreach ($createdIds as $taskId) {
            $task = $this->getTask($userId, $taskId);
            if ($task) {
                $created[] = $task;
            }
        }
        return $created;
    }
    public function getPlannerQuestion(int $userId, int $questionId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT q.id, q.question_year, q.category, q.prompt_en,
                    t.id AS task_id, t.subject_id, t.topic_id,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    st.code AS topic_code, st.title_en AS topic_title_en
             FROM planner_tasks t
             JOIN planner_months m ON m.id = t.month_id AND m.status = \'active\'
             JOIN planner_profiles p ON p.user_id = t.user_id AND p.active = 1 AND p.exam_id = m.exam_id
             JOIN questions q ON q.id = t.question_id AND q.exam_id = m.exam_id
             JOIN exam_subjects s ON s.id = q.subject_id
             LEFT JOIN syllabus_topics st ON st.id = q.topic_id
             WHERE t.user_id = ? AND q.id = ?
               AND q.publication_state = \'published\' AND q.authoritative = 1
             ORDER BY t.id ASC LIMIT 1'
        );
        $statement->execute([$userId, $questionId]);
        $question = $statement->fetch();
        if (!$question) {
            return null;
        }
        $options = $this->db->prepare(
            'SELECT option_key, option_text_en, display_order
             FROM question_options
             WHERE question_id = ? AND option_key IN (\'A\', \'B\', \'C\', \'D\')
             ORDER BY display_order ASC, id ASC'
        );
        $options->execute([$questionId]);
        $question['id'] = (int)$question['id'];
        $question['task_id'] = (int)$question['task_id'];
        $question['options'] = $options->fetchAll();
        return $question;
    }
    public function getLatestDiagnosticAttempt(int $userId, int $examId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, exam_id, question_count, started_at, completed_at, score, status
             FROM diagnostic_attempts
             WHERE user_id = ? AND exam_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$userId, $examId]);
        $attempt = $statement->fetch();
        return $attempt ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function getDiagnosticQuestions(int $examId, int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT q.id, q.subject_id, q.question_year, q.category,
                    q.prompt_en, q.explanation_en,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    s.display_order
             FROM questions q
             JOIN exam_subjects s ON s.id = q.subject_id
             WHERE q.exam_id = ? AND q.publication_state = \'published\'
               AND q.authoritative = 1 AND s.active = 1
               AND q.correct_option_key IN (\'A\', \'B\', \'C\', \'D\')
               AND (SELECT COUNT(*) FROM question_options qo
                    WHERE qo.question_id = q.id AND qo.option_key IN (\'A\', \'B\', \'C\', \'D\')) = 4
             ORDER BY s.display_order ASC, q.id ASC
             LIMIT 120'
        );
        $statement->execute([$examId]);
        $grouped = [];
        foreach ($statement->fetchAll() as $question) {
            $grouped[(int)$question['subject_id']][] = $question;
        }
        $selected = [];
        while (count($selected) < $limit) {
            $added = false;
            foreach ($grouped as &$subjectQuestions) {
                if ($subjectQuestions === []) {
                    continue;
                }
                $selected[] = array_shift($subjectQuestions);
                $added = true;
                if (count($selected) >= $limit) {
                    break;
                }
            }
            unset($subjectQuestions);
            if (!$added) {
                break;
            }
        }
        if ($selected === []) {
            return [];
        }
        $questionIds = array_map(static fn(array $row): int => (int)$row['id'], $selected);
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $optionsStatement = $this->db->prepare(
            'SELECT question_id, option_key, option_text_en, display_order
             FROM question_options
             WHERE question_id IN (' . $placeholders . ')
             ORDER BY question_id ASC, display_order ASC, id ASC'
        );
        $optionsStatement->execute($questionIds);
        $options = [];
        foreach ($optionsStatement->fetchAll() as $option) {
            $options[(int)$option['question_id']][] = [
                'option_key' => $option['option_key'],
                'option_text_en' => $option['option_text_en'],
                'display_order' => (int)$option['display_order'],
            ];
        }
        foreach ($selected as &$question) {
            $question['id'] = (int)$question['id'];
            $question['options'] = $options[(int)$question['id']] ?? [];
            unset($question['display_order']);
        }
        unset($question);
        return $selected;
    }

    /** @param list<int> $questionIds */
    public function createDiagnosticAttempt(int $userId, int $examId, array $questionIds): int
    {
        if ($questionIds === []) {
            throw new RuntimeException('No diagnostic questions are available.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE diagnostic_attempts SET status = \'abandoned\'
                 WHERE user_id = ? AND exam_id = ? AND status = \'started\''
            )->execute([$userId, $examId]);
            $attemptInsert = $this->db->prepare(
                'INSERT INTO diagnostic_attempts (user_id, exam_id, question_count, status)
                 VALUES (?, ?, ?, \'started\')'
            );
            $attemptInsert->execute([$userId, $examId, count($questionIds)]);
            $attemptId = (int)$this->db->lastInsertId();
            $answerInsert = $this->db->prepare(
                'INSERT INTO diagnostic_answers (attempt_id, question_id)
                 VALUES (?, ?)'
            );
            foreach ($questionIds as $questionId) {
                $answerInsert->execute([$attemptId, $questionId]);
            }
            $this->db->commit();
            return $attemptId;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function getDiagnosticAttempt(int $userId, int $attemptId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, exam_id, question_count, started_at, completed_at, score, status
             FROM diagnostic_attempts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $statement->execute([$attemptId, $userId]);
        $attempt = $statement->fetch();
        return $attempt ?: null;
    }

    public function getDiagnosticQuestionForAttempt(int $userId, int $attemptId, int $questionId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT q.id, q.correct_option_key, da.selected_option_key,
                    da.is_correct, da.answered_at
             FROM diagnostic_answers da
             JOIN diagnostic_attempts a ON a.id = da.attempt_id AND a.user_id = ?
             JOIN questions q ON q.id = da.question_id
             WHERE da.attempt_id = ? AND da.question_id = ? LIMIT 1'
        );
        $statement->execute([$userId, $attemptId, $questionId]);
        $question = $statement->fetch();
        return $question ?: null;
    }

    public function saveDiagnosticAnswer(int $userId, int $attemptId, int $questionId, string $optionKey): array
    {
        $attempt = $this->getDiagnosticAttempt($userId, $attemptId);
        if (!$attempt || $attempt['status'] !== 'started') {
            throw new RuntimeException('Diagnostic attempt is not active.');
        }
        $question = $this->getDiagnosticQuestionForAttempt($userId, $attemptId, $questionId);
        if (!$question) {
            throw new RuntimeException('Question does not belong to this diagnostic attempt.');
        }
        $optionStatement = $this->db->prepare(
            'SELECT 1 FROM question_options WHERE question_id = ? AND option_key = ? LIMIT 1'
        );
        $optionStatement->execute([$questionId, $optionKey]);
        if (!$optionStatement->fetchColumn()) {
            throw new RuntimeException('Selected option is not valid for this question.');
        }
        $isCorrect = hash_equals((string)$question['correct_option_key'], $optionKey) ? 1 : 0;
        $this->db->prepare(
            'UPDATE diagnostic_answers
             SET selected_option_key = ?, is_correct = ?, answered_at = CURRENT_TIMESTAMP
             WHERE attempt_id = ? AND question_id = ?'
        )->execute([$optionKey, $isCorrect, $attemptId, $questionId]);
        return ['is_correct' => (bool)$isCorrect, 'selected_option_key' => $optionKey];
    }

    public function completeDiagnostic(int $userId, int $attemptId): array
    {
        $attempt = $this->getDiagnosticAttempt($userId, $attemptId);
        if (!$attempt || $attempt['status'] !== 'started') {
            throw new RuntimeException('Diagnostic attempt is not active.');
        }
        $profile = $this->getProfile($userId);
        if (!$profile || (int)$profile['exam_id'] !== (int)$attempt['exam_id']) {
            throw new RuntimeException('Diagnostic attempt does not match the active planner exam.');
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN selected_option_key IS NOT NULL THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
             FROM diagnostic_answers WHERE attempt_id = ?'
        );
        $statement->execute([$attemptId]);
        $result = $statement->fetch() ?: ['total' => 0, 'answered' => 0, 'correct' => 0];
        $total = (int)$result['total'];
        $answered = (int)$result['answered'];
        if ($total < 1 || $answered !== $total) {
            throw new RuntimeException('Answer every diagnostic question before completing the assessment.');
        }
        $score = (float)$result['correct'] / $total;
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE diagnostic_attempts
                 SET completed_at = CURRENT_TIMESTAMP, score = ?, status = \'completed\'
                 WHERE id = ? AND user_id = ? AND status = \'started\''
            )->execute([$score, $attemptId, $userId]);
            $this->db->prepare(
                'UPDATE planner_profiles
                 SET diagnostic_completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = ?'
            )->execute([$userId]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return [
            'attempt_id' => $attemptId,
            'question_count' => $total,
            'correct_count' => (int)$result['correct'],
            'score' => $score,
        ];
    }
    /** @return list<array<string,mixed>> */
    public function getDiagnosticAttemptQuestions(int $userId, int $attemptId): array
    {
        $statement = $this->db->prepare(
            'SELECT q.id, q.subject_id, q.question_year, q.category,
                    q.prompt_en, da.selected_option_key,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    da.id AS answer_id
             FROM diagnostic_answers da
             JOIN diagnostic_attempts a ON a.id = da.attempt_id AND a.user_id = ?
             JOIN questions q ON q.id = da.question_id
             JOIN exam_subjects s ON s.id = q.subject_id
             WHERE da.attempt_id = ?
             ORDER BY da.id ASC'
        );
        $statement->execute([$userId, $attemptId]);
        $questions = $statement->fetchAll();
        if ($questions === []) {
            return [];
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $questions);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $optionsStatement = $this->db->prepare(
            'SELECT question_id, option_key, option_text_en, display_order
             FROM question_options
             WHERE question_id IN (' . $placeholders . ')
             ORDER BY question_id ASC, display_order ASC, id ASC'
        );
        $optionsStatement->execute($ids);
        $options = [];
        foreach ($optionsStatement->fetchAll() as $option) {
            $options[(int)$option['question_id']][] = [
                'option_key' => $option['option_key'],
                'option_text_en' => $option['option_text_en'],
                'display_order' => (int)$option['display_order'],
            ];
        }
        foreach ($questions as &$question) {
            $question['id'] = (int)$question['id'];
            $question['selected_option_key'] = $question['selected_option_key'] ?: null;
            $question['options'] = $options[(int)$question['id']] ?? [];
            unset($question['answer_id']);
        }
        unset($question);
        return $questions;
    }
    public function getTask(int $userId, int $taskId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.scheduled_date, t.task_type, t.subject_id,
                    t.origin, t.unit_id, t.topic_id, t.question_id, t.title_en,
                    t.estimated_minutes, t.display_order, t.status,
                    t.rescheduled_from_task_id, t.completed_at, t.completion_note,
                    t.month_id, t.week_id, m.exam_id,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    st.code AS topic_code, st.title_en AS topic_title_en
             FROM planner_tasks t
             JOIN planner_months m ON m.id = t.month_id AND m.status = \'active\'
             JOIN exam_subjects s ON s.id = t.subject_id
             LEFT JOIN syllabus_topics st ON st.id = t.topic_id
             LEFT JOIN questions q ON q.id = t.question_id
             WHERE t.id = ? AND t.user_id = ? LIMIT 1'
        );
        $statement->execute([$taskId, $userId]);
        $task = $statement->fetch();
        return $task ?: null;
    }

    public function updateTaskStatus(int $userId, int $taskId, string $status, ?string $completionNote): array
    {
        $task = $this->getTask($userId, $taskId);
        if (!$task) {
            throw new RuntimeException('Study task was not found.');
        }
        $allowedTransitions = [
            'planned' => ['in_progress', 'completed', 'skipped'],
            'in_progress' => ['completed', 'skipped'],
            'completed' => [],
            'skipped' => [],
            'rescheduled' => [],
            'cancelled' => [],
        ];
        if (!in_array($status, $allowedTransitions[(string)$task['status']] ?? [], true)) {
            throw new RuntimeException('This task cannot be moved to the requested state.');
        }
        $note = in_array($status, ['completed', 'skipped'], true) ? $completionNote : null;
        $completedAt = in_array($status, ['completed', 'skipped'], true) ? gmdate('Y-m-d H:i:s') : null;
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE planner_tasks
                 SET status = ?, completed_at = ?, completion_note = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ?'
            );
            $statement->execute([$status, $completedAt, $note, $taskId, $userId]);
            $this->recordTaskEvent($userId, $taskId, 'task_status_changed', [
                'from' => (string)$task['status'],
                'to' => $status,
            ]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $updated = $this->getTask($userId, $taskId);
        if (!$updated) {
            throw new RuntimeException('Study task could not be loaded after updating.');
        }
        return $updated;
    }

    public function rescheduleTask(int $userId, int $taskId, string $scheduledDate): array
    {
        $task = $this->getTask($userId, $taskId);
        if (!$task) {
            throw new RuntimeException('Study task was not found.');
        }
        if (in_array((string)$task['status'], ['completed', 'skipped', 'rescheduled', 'cancelled'], true)) {
            throw new RuntimeException('Only an active task can be rescheduled.');
        }
        $year = (int)substr($scheduledDate, 0, 4);
        $month = (int)substr($scheduledDate, 5, 2);
        $monthStatement = $this->db->prepare(
            'SELECT id FROM planner_months
             WHERE user_id = ? AND exam_id = ? AND calendar_year = ?
               AND calendar_month = ? AND status = \'active\'
             ORDER BY generated_at DESC, id DESC LIMIT 1'
        );
        $monthStatement->execute([$userId, (int)$task['exam_id'], $year, $month]);
        $monthId = (int)$monthStatement->fetchColumn();
        if ($monthId < 1) {
            throw new RuntimeException('Generate the target month before moving a task into it.');
        }
        $weekStatement = $this->db->prepare(
            'SELECT id FROM planner_weeks
             WHERE user_id = ? AND month_id = ? AND status = \'active\'
               AND week_start <= ? AND week_end >= ?
             ORDER BY id DESC LIMIT 1'
        );
        $weekStatement->execute([$userId, $monthId, $scheduledDate, $scheduledDate]);
        $weekId = $weekStatement->fetchColumn();
        $orderStatement = $this->db->prepare(
            'SELECT COALESCE(MAX(display_order), 0) + 1
             FROM planner_tasks WHERE user_id = ? AND month_id = ? AND scheduled_date = ?'
        );
        $orderStatement->execute([$userId, $monthId, $scheduledDate]);
        $displayOrder = (int)$orderStatement->fetchColumn();

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO planner_tasks
                    (user_id, month_id, week_id, scheduled_date, task_type,
                     subject_id, unit_id, topic_id, question_id, title_en,
                     estimated_minutes, display_order, status, rescheduled_from_task_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'planned\', ?)'
            );
            $insert->execute([
                $userId,
                $monthId,
                $weekId === false ? null : (int)$weekId,
                $scheduledDate,
                $task['task_type'],
                (int)$task['subject_id'],
                $task['unit_id'] === null ? null : (int)$task['unit_id'],
                $task['topic_id'] === null ? null : (int)$task['topic_id'],
                $task['question_id'] === null ? null : (int)$task['question_id'],
                $task['title_en'],
                (int)$task['estimated_minutes'],
                $displayOrder,
                $taskId,
            ]);
            $newTaskId = (int)$this->db->lastInsertId();
            $this->db->prepare(
                'UPDATE planner_tasks
                 SET status = \'rescheduled\', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ?'
            )->execute([$taskId, $userId]);
            $this->recordTaskEvent($userId, $taskId, 'task_rescheduled', [
                'new_task_id' => $newTaskId,
                'from' => (string)$task['scheduled_date'],
                'to' => $scheduledDate,
            ]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $updated = $this->getTask($userId, $newTaskId);
        if (!$updated) {
            throw new RuntimeException('Rescheduled task could not be loaded.');
        }
        return $updated;
    }

    /** @param list<int> $taskIds */
    public function reorderTasks(int $userId, string $scheduledDate, array $taskIds): array
    {
        if ($taskIds === []) {
            throw new RuntimeException('Choose at least one task to reorder.');
        }
        $normalized = array_values(array_unique(array_map('intval', $taskIds)));
        if (count($normalized) !== count($taskIds)) {
            throw new RuntimeException('Task IDs must be unique positive integers.');
        }
        foreach ($normalized as $taskId) {
            if ($taskId < 1) {
                throw new RuntimeException('Task IDs must be positive integers.');
            }
            $task = $this->getTask($userId, $taskId);
            if (!$task || (string)$task['scheduled_date'] !== $scheduledDate) {
                throw new RuntimeException('Every task must belong to the selected study date.');
            }
            if (in_array((string)$task['status'], ['completed', 'skipped', 'rescheduled', 'cancelled'], true)) {
                throw new RuntimeException('Completed or inactive tasks cannot be reordered.');
            }
        }
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE planner_tasks SET display_order = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ?'
            );
            foreach ($normalized as $index => $taskId) {
                $update->execute([$index + 1, $taskId, $userId]);
                $this->recordTaskEvent($userId, $taskId, 'task_reordered', ['display_order' => $index + 1]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->findDayTasks($userId, $scheduledDate);
    }

    private function recordTaskEvent(int $userId, int $taskId, string $eventType, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO planner_events (user_id, task_id, event_type, event_payload)
             VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $taskId,
            $eventType,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}