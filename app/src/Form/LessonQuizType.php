<?php

namespace App\Form;

use App\Entity\LessonQuiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LessonQuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revision', HiddenType::class, ['mapped' => false, 'data' => (string) $options['data']->getRevision()]);
        $prototype = (new QuizQuestion())->addAnswer(new QuizAnswer())->addAnswer(new QuizAnswer());
        $builder->add('title', TextType::class, ['label' => 'Назва тесту'])
            ->add('description', TextareaType::class, ['label' => 'Інструкції', 'required' => false])
            ->add('maxAttempts', IntegerType::class, ['label' => 'Кількість спроб для студента', 'attr' => ['min' => 1, 'max' => 10]])
            ->add('published', CheckboxType::class, ['label' => 'Опублікувати для студентів', 'required' => false])
            ->add('questions', CollectionType::class, ['entry_type' => QuizQuestionType::class, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false,
                'prototype_name' => '__question__', 'prototype_data' => $prototype, 'label' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => LessonQuiz::class, 'csrf_token_id' => 'lesson_quiz']); }
}
