<?php

namespace App\Form;

use App\Entity\User;
use App\Security\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('role', EnumType::class, [
            'class' => UserRole::class,
            'choice_label' => static fn (UserRole $role): string => $role->label(),
            'label' => 'Роль',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'user_role',
        ]);
    }
}
