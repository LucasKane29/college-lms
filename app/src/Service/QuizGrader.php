<?php

namespace App\Service;

use App\Entity\LessonQuiz;
use App\Entity\QuizAttempt;
use App\Entity\User;

final class QuizGrader
{
    /** @param array<string, mixed> $data */
    public function grade(LessonQuiz $quiz, User $student, int $number, array $data): QuizAttempt
    {
        $score = $max = 0; $responses = [];
        foreach ($quiz->getQuestions() as $question) {
            $selected = (array) ($data['q_'.$question->getId()] ?? []);
            $selected = array_values(array_unique(array_map('strval', $selected)));
            $correct = $chosenTexts = $correctTexts = [];
            foreach ($question->getAnswers() as $answer) {
                if ($answer->isCorrect()) { $correct[] = (string) $answer->getId(); $correctTexts[] = $answer->getText(); }
                if (in_array((string) $answer->getId(), $selected, true)) $chosenTexts[] = $answer->getText();
            }
            sort($selected); sort($correct);
            $earned = $selected === $correct ? $question->getPoints() : 0;
            $score += $earned; $max += $question->getPoints();
            $responses[] = ['question' => $question->getText(), 'chosen' => $chosenTexts, 'correct' => $correctTexts, 'score' => $earned, 'max' => $question->getPoints()];
        }

        return new QuizAttempt($quiz, $student, $number, $score, $max, $responses);
    }
}
