<?php

namespace App\Form;

use App\Entity\Discipline;
use App\Entity\LessonTopic;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ScheduleTopicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('topic', EntityType::class, [
            'class' => LessonTopic::class, 'label' => 'Заняття', 'choice_label' => 'name',
            'required' => false, 'placeholder' => 'Заняття ще не обрано',
            'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('topic')
                ->where('topic.discipline = :discipline')->setParameter('discipline', $options['discipline'])->orderBy('topic.id', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'schedule_topic']);
        $resolver->setRequired('discipline');
        $resolver->setAllowedTypes('discipline', Discipline::class);
    }
}
