<?php

namespace App\Form;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\GroupRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DisciplineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['manage_teachers']) {
            $builder->add('teachers', EntityType::class, [
                'class' => User::class, 'label' => 'Викладачі',
                'choice_label' => static fn (User $user): string => $user->getFullName().' — '.$user->getEmail(),
                'query_builder' => static fn (UserRepository $users) => $users->createQueryBuilder('u')
                    ->where("u.roles LIKE :role ESCAPE '!'")->setParameter('role', '%"ROLE!_TEACHER"%')
                    ->orderBy('u.fullName', 'ASC')->addOrderBy('u.id', 'ASC'),
                'multiple' => true, 'expanded' => false, 'by_reference' => false,
                'attr' => ['size' => 6],
                'help' => 'Оберіть одного або кількох викладачів. Для вибору кількох утримуйте Ctrl (Cmd на macOS).',
            ]);
        }
        $builder
            ->add('name', TextType::class, [
                'label' => 'Назва дисципліни', 'empty_data' => '', 'attr' => ['maxlength' => 150],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Опис', 'required' => false, 'attr' => ['maxlength' => 5000, 'rows' => 4],
            ])
            ->add('groups', EntityType::class, [
                'class' => Group::class, 'label' => 'Групи',
                'choice_label' => static fn (Group $group): string => $group->getName().' (#'.$group->getId().')',
                'query_builder' => static fn (GroupRepository $groups) => $groups->createQueryBuilder('g')->orderBy('g.name', 'ASC')->addOrderBy('g.id', 'ASC'),
                'multiple' => true, 'expanded' => true, 'required' => false, 'by_reference' => false,
                'help' => 'Можна обрати кілька груп або додати їх пізніше.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Discipline::class, 'csrf_token_id' => 'discipline', 'manage_teachers' => false]);
        $resolver->setAllowedTypes('manage_teachers', 'bool');
    }
}
