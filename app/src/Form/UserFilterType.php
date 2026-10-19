<?php

namespace App\Form;

use App\Entity\Group;
use App\Security\UserRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', IntegerType::class, [
                'label' => 'ID', 'required' => false,
                'constraints' => [new Assert\Positive()], 'attr' => ['min' => 1],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Ім’я та прізвище', 'required' => false,
                'constraints' => [new Assert\Length(max: 100)],
                'attr' => ['maxlength' => 100, 'placeholder' => 'Частина імені'],
            ])
            ->add('email', TextType::class, [
                'label' => 'Email', 'required' => false,
                'constraints' => [new Assert\Length(max: 180)],
                'attr' => ['maxlength' => 180, 'placeholder' => 'Частина адреси'],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'Номер телефона', 'required' => false,
                'constraints' => [new Assert\Length(max: 16)], 'attr' => ['maxlength' => 16],
            ])
            ->add('birthDate', LocalizedDateType::class, [
                'label' => 'Дата народження', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
            ])
            ->add('role', EnumType::class, [
                'class' => UserRole::class,
                'choice_label' => static fn (UserRole $role): string => $role->label(),
                'label' => 'Призначена роль', 'required' => false, 'placeholder' => 'Усі ролі',
            ])
            ->add('studentGroup', EntityType::class, [
                'class' => Group::class, 'choice_label' => static fn (Group $group): string => $group->getName().' (#'.$group->getId().')',
                'label' => 'Група', 'required' => false, 'placeholder' => 'Усі групи',
            ])
            ->add('password', ChoiceType::class, [
                'label' => 'Пароль', 'required' => false, 'placeholder' => 'Будь-який стан',
                'choices' => ['Встановлено' => 'set', 'Не встановлено' => 'empty'],
            ])
            ->add('createdAtFrom', LocalizedDateType::class, [
                'label' => 'Зареєстровано від', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
            ])
            ->add('createdAtTo', LocalizedDateType::class, [
                'label' => 'Зареєстровано до (включно)', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
            ])
            ->add('perPage', ChoiceType::class, [
                'label' => 'Записів на сторінці',
                'choices' => [10 => 10, 20 => 20, 50 => 50, 100 => 100],
                'placeholder' => false, 'empty_data' => '20',
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET', 'csrf_protection' => false, 'allow_extra_fields' => true,
        ]);
    }
}
