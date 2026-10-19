<?php

namespace App\Form;

use App\Entity\Group;
use App\Entity\User;
use App\Repository\GroupRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

class AssignStudentGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('group', EntityType::class, [
            'class' => Group::class, 'choice_label' => static fn (Group $group): string => $group->getName().' (#'.$group->getId().')',
            'label' => 'Група', 'placeholder' => 'Оберіть групу',
            'query_builder' => static fn (GroupRepository $groups) => $groups->manageableBy($options['actor'], $options['admin']),
            'constraints' => [new NotNull(message: 'Оберіть групу.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('actor')->setAllowedTypes('actor', User::class);
        $resolver->setDefaults(['admin' => false, 'csrf_token_id' => 'assign_student_group']);
        $resolver->setAllowedTypes('admin', 'bool');
    }
}
