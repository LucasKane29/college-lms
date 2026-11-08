<?php

namespace App\Form;

use App\Entity\QuizQuestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuizQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('text', TextareaType::class, ['label' => 'Запитання', 'attr' => ['rows' => 3]])
            ->add('kind', ChoiceType::class, ['label' => 'Тип запитання', 'choices' => ['Одна правильна відповідь' => 'single', 'Кілька правильних відповідей' => 'multiple']])
            ->add('points', IntegerType::class, ['label' => 'Бали', 'attr' => ['min' => 1, 'max' => 100]])
            ->add('answers', CollectionType::class, ['entry_type' => QuizAnswerType::class, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'prototype_name' => '__answer__', 'label' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => QuizQuestion::class]); }
}
