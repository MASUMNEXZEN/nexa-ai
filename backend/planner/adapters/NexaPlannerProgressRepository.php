<?php
declare(strict_types=1);

namespace Nexa\Planner\Adapters;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Host adapter for answer-level outcomes and topic mastery.
 *
 * Keeping this separate from calendar persistence makes the planner portable:
 * the domain consumes progress rows, while the host decides how attempts are
 * authenticated and stored.
 */
final class NexaPlannerProgressRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function getProgress(int $userId, int $examId): array
    {
        $statement = $this->db->prepare(
            'SELECT tp.topic_id, tp.attempts, tp.correct_attempts, tp.recent_accuracy,
                    tp.mastery_status, tp.last_studied_at, tp.next_revision_at,
                    s.code AS subject_code, s.name_en AS subject_name_en,
                    t.code AS topic_code, t.title_en AS topic_title_en
             FROM topic_progress tp
             JOIN syllabus_topics t ON t.id = tp.topic_id
             JOIN syllabus_units u ON u.id = t.unit_id AND u.exam_id = ?
             JOIN exam_subjects s ON s.id = u.subject_id
             WHERE tp.user_id = ?
             ORDER BY s.display_order ASC, t.code ASC'
        );
        $statement->execute([$examId, $userId]);
        return $statement->fetchAll();
    }

    public function recordDiagnostic(int $userId, int $examId, int $attemptId): void
    {
        $attemptStatement = $this->db->prepare(
            'SELECT id, status, question_count
             FROM diagnostic_attempts
             WHERE id = ? AND user_id = ? AND exam_id = ? LIMIT 1'
        );
        $attemptStatement->execute([$attemptId, $userId, $examId]);
        $attempt = $attemptStatement->fetch();
        if (!$attempt || $attempt['status'] !== 'started') {
            throw new RuntimeException('Diagnostic attempt is not available for progress recording.');
        }

        $answersStatement = $this->db->prepare(
            'SELECT da.question_id, q.topic_id, da.selected_option_key, da.is_correct
             FROM diagnostic_answers da
             JOIN questions q ON q.id = da.question_id AND q.exam_id = ?
             WHERE da.attempt_id = ?                AND da.selected_option_key IS NOT NULL'
        );
        $answersStatement->execute([$examId, $attemptId]);
        $answers = $answersStatement->fetchAll();
        if (count($answers) !== (int)$attempt['question_count']) {
            throw new RuntimeException('Complete every diagnostic question before saving progress.');
        }

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT OR IGNORE INTO planner_question_attempts
                    (user_id, exam_id, question_id, topic_id, attempt_id, source_type,
                     selected_option_key, is_correct)
                 VALUES (?, ?, ?, ?, ?, \'diagnostic\', ?, ?)'
            );
            $topicIds = [];
            foreach ($answers as $answer) {
                $topicId = (int)$answer['topic_id'];
                $insert->execute([
                    $userId,
                    $examId,
                    (int)$answer['question_id'],
                    $topicId,
                    $attemptId,
                    $answer['selected_option_key'],
                    (int)$answer['is_correct'],
                ]);
                if ($topicId > 0) {
                    $topicIds[$topicId] = true;
                }
            }
            foreach (array_keys($topicIds) as $topicId) {
                $this->recalculateTopicProgress($userId, (int)$topicId);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function recordTaskOutcome(int $userId, array $task, string $status): void
    {
        $topicId = (int)($task['topic_id'] ?? 0);
        if ($topicId < 1 || !in_array($status, ['completed', 'skipped'], true)) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT OR IGNORE INTO topic_progress
                    (user_id, topic_id, mastery_status)
                 VALUES (?, ?, \'not_started\')'
            )->execute([$userId, $topicId]);

            $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
            if ($status === 'skipped') {
                $this->db->prepare(
                    'UPDATE topic_progress
                     SET mastery_status = CASE
                           WHEN mastery_status = \'mastered\' THEN mastery_status
                           ELSE \'revision\'
                         END,
                         next_revision_at = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE user_id = ? AND topic_id = ?'
                )->execute([$today->format('Y-m-d'), $userId, $topicId]);
            } else {
                $revisionDays = match ((string)($task['task_type'] ?? 'learn')) {
                    'learn' => 1,
                    'practice', 'pyq' => 3,
                    'revision', 'error_review' => 15,
                    default => 1,
                };
                $this->db->prepare(
                    'UPDATE topic_progress
                     SET mastery_status = CASE
                           WHEN mastery_status = \'not_started\' THEN \'learning\'
                           ELSE mastery_status
                         END,
                         last_studied_at = CURRENT_TIMESTAMP,
                         next_revision_at = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE user_id = ? AND topic_id = ?'
                )->execute([
                    $today->modify('+' . $revisionDays . ' days')->format('Y-m-d'),
                    $userId,
                    $topicId,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{question_id:int,topic_id:?int,is_correct:bool} */
    public function recordAnswer(
        int $userId,
        int $questionId,
        string $optionKey,
        ?int $taskId = null,
        string $sourceType = 'practice'
    ): array {
        if (!in_array($sourceType, ['planner_task', 'practice', 'mock'], true)) {
            throw new RuntimeException('The planner answer source is invalid.');
        }
        $profileStatement = $this->db->prepare(
            'SELECT exam_id FROM planner_profiles WHERE user_id = ? AND active = 1 LIMIT 1'
        );
        $profileStatement->execute([$userId]);
        $examId = (int)$profileStatement->fetchColumn();
        if ($examId < 1) {
            throw new RuntimeException('Complete your study planner profile first.');
        }
        if ($taskId !== null) {
            $taskStatement = $this->db->prepare(
                'SELECT t.question_id
                 FROM planner_tasks t
                 JOIN planner_months m ON m.id = t.month_id AND m.status = \'active\'
                 WHERE t.id = ? AND t.user_id = ? LIMIT 1'
            );
            $taskStatement->execute([$taskId, $userId]);
            $linkedQuestionId = $taskStatement->fetchColumn();
            if ($linkedQuestionId === false || (int)$linkedQuestionId !== $questionId) {
                throw new RuntimeException('This question is not linked to your planner task.');
            }
        }

        $questionStatement = $this->db->prepare(
            'SELECT id, exam_id, topic_id, correct_option_key, explanation_en
             FROM questions
             WHERE id = ? AND exam_id = ? AND publication_state = \'published\'
               AND authoritative = 1 LIMIT 1'
        );
        $questionStatement->execute([$questionId, $examId]);
        $question = $questionStatement->fetch();
        if (!$question) {
            throw new RuntimeException('This question is not available in your planner.');
        }
        $optionStatement = $this->db->prepare(
            'SELECT 1 FROM question_options WHERE question_id = ? AND option_key = ? LIMIT 1'
        );
        $optionStatement->execute([$questionId, $optionKey]);
        if (!$optionStatement->fetchColumn()) {
            throw new RuntimeException('Selected option is not valid for this question.');
        }
        $isCorrect = hash_equals((string)$question['correct_option_key'], $optionKey) ? 1 : 0;

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO planner_question_attempts
                    (user_id, exam_id, question_id, topic_id, task_id, source_type,
                     selected_option_key, is_correct)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $examId,
                $questionId,
                $question['topic_id'] === null ? null : (int)$question['topic_id'],
                $taskId,
                $sourceType,
                $optionKey,
                $isCorrect,
            ]);
            if ($question['topic_id'] !== null) {
                $this->recalculateTopicProgress($userId, (int)$question['topic_id']);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return [
            'question_id' => $questionId,
            'topic_id' => $question['topic_id'] === null ? null : (int)$question['topic_id'],
            'is_correct' => (bool)$isCorrect,
            'explanation_en' => $question['explanation_en'] ?? null,
        ];
    }

    private function recalculateTopicProgress(int $userId, int $topicId): void
    {
        $recentStatement = $this->db->prepare(
            'SELECT is_correct
             FROM planner_question_attempts
             WHERE user_id = ? AND topic_id = ?
             ORDER BY answered_at DESC, id DESC
             LIMIT 10'
        );
        $recentStatement->execute([$userId, $topicId]);
        $recent = $recentStatement->fetchAll();
        if ($recent === []) {
            return;
        }
        $totalStatement = $this->db->prepare(
            'SELECT COUNT(*) AS attempts, COALESCE(SUM(is_correct), 0) AS correct_attempts,
                    (SELECT is_correct FROM planner_question_attempts
                     WHERE user_id = ? AND topic_id = ?
                     ORDER BY answered_at DESC, id DESC LIMIT 1) AS latest_correct
             FROM planner_question_attempts
             WHERE user_id = ? AND topic_id = ?'
        );
        $totalStatement->execute([$userId, $topicId, $userId, $topicId]);
        $totals = $totalStatement->fetch() ?: ['attempts' => 0, 'correct_attempts' => 0, 'latest_correct' => 0];
        $recentCorrect = array_sum(array_map(static fn(array $row): int => (int)$row['is_correct'], $recent));
        $recentAccuracy = $recentCorrect / count($recent);
        $mastery = count($recent) >= 10 && $recentAccuracy >= 0.80
            ? 'mastered'
            : ($recentAccuracy < 0.50 ? 'learning' : 'practice');
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
        $nextRevision = ((int)$totals['latest_correct'] === 1)
            ? $today->modify('+1 day')->format('Y-m-d')
            : $today->format('Y-m-d');
        $this->db->prepare(
            'INSERT INTO topic_progress
                (user_id, topic_id, attempts, correct_attempts, recent_accuracy,
                 mastery_status, last_studied_at, next_revision_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
             ON CONFLICT(user_id, topic_id) DO UPDATE SET
                attempts = excluded.attempts,
                correct_attempts = excluded.correct_attempts,
                recent_accuracy = excluded.recent_accuracy,
                mastery_status = excluded.mastery_status,
                last_studied_at = excluded.last_studied_at,
                next_revision_at = excluded.next_revision_at,
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            $userId,
            $topicId,
            (int)$totals['attempts'],
            (int)$totals['correct_attempts'],
            $recentAccuracy,
            $mastery,
            $nextRevision,
        ]);
    }
}
