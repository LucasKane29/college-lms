<?php

namespace App\Form;

use App\Entity\LessonQuiz;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class QuizSubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $quiz = $options['quiz'];
        $builder->add('revision', HiddenType::class, ['data' => (string) $quiz->getRevision()]);
        foreach ($quiz->getQuestions() as $question) {
            $choices = [];
            // Keys include an ordinal so identical answer texts remain separate choices.
            foreach ($question->getAnswers() as $index => $answer) $choices[($index + 1).'. '.$answer->getText()] = (string) $answer->getId();
            $multiple = $question->getKind() === 'multiple';
            $builder->add('q_'.$question->getId(), ChoiceType::class, ['label' => $question->getText().' ('.$question->getPoints().' б.)',
                'choices' => $choices, 'expanded' => true, 'multiple' => $multiple, 'required' => true,
                'constraints' => $multiple ? [new Assert\Count(min: 1)] : [new Assert\NotBlank()],
                'help' => $multiple ? 'Оберіть усі правильні відповіді.' : 'Оберіть одну відповідь.',
            ]);
        }
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'quiz_submission']);
        $resolver->setRequired('quiz'); $resolver->setAllowedTypes('quiz', LessonQuiz::class);
    }
}
