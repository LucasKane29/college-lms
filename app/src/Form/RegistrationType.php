<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Ім’я та прізвище',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'name', 'maxlength' => 100, 'placeholder' => 'Олена Коваленко'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Електронна пошта',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'placeholder' => 'name@example.com'],
            ])
            ->add('phoneNumber', PhoneNumberType::class, ['required' => true])
            ->add('birthDate', LocalizedDateType::class, [
                'label' => 'Дата народження', 'required' => true,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
                'invalid_message' => 'Вкажіть коректну дату народження.',
                'attr' => ['data-controller' => 'datepicker', 'lang' => 'uk', 'autocomplete' => 'bday', 'max' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Паролі не збігаються.',
                'first_options' => ['label' => 'Пароль', 'help' => 'Від 8 до 72 символів. Використовуйте унікальний пароль.'],
                'second_options' => ['label' => 'Повторіть пароль'],
                'options' => [
                    'attr' => ['autocomplete' => 'new-password', 'minlength' => 8, 'maxlength' => 72],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Вкажіть пароль.'),
                    new Assert\Length(min: 8, max: 72, minMessage: 'Пароль має містити щонайменше {{ limit }} символів.', maxMessage: 'Пароль має містити до {{ limit }} символів.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'registration',
            'validation_groups' => ['Default', 'registration'],
        ]);
    }
}
