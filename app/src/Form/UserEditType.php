<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Ім’я та прізвище', 'empty_data' => '',
                'attr' => ['autocomplete' => 'name', 'maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Електронна пошта', 'empty_data' => '',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
            ])
            ->add('phoneNumber', PhoneNumberType::class, ['required' => false])
            ->add('birthDate', LocalizedDateType::class, [
                'label' => 'Дата народження', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
                'invalid_message' => 'Вкажіть коректну дату народження.',
                'attr' => ['lang' => 'uk', 'autocomplete' => 'bday', 'max' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default'],
            'csrf_token_id' => 'admin_user_edit',
        ]);
    }
}
