<?php

namespace App\Form;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\Schedule;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ScheduleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('discipline', EntityType::class, [
            'class' => Discipline::class, 'label' => 'Дисципліна', 'placeholder' => 'Оберіть дисципліну',
            'choice_label' => static fn (Discipline $discipline) => $discipline->getName().' — '.$discipline->getTeacher()->getFullName(),
            'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('d')
                ->join('d.groups', 'g')->join('d.teacher', 't')->addSelect('t')->where('g = :group')->setParameter('group', $options['group'])
                ->orderBy('d.name', 'ASC')->addOrderBy('d.id', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Schedule::class, 'csrf_token_id' => 'schedule']);
        $resolver->setRequired('group');
        $resolver->setAllowedTypes('group', Group::class);
    }
}
