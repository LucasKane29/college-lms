<?php

namespace App\Form;

use App\Entity\Pair;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PairType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('number', IntegerType::class, ['label' => 'Номер пари', 'attr' => ['min' => 1, 'max' => 99]]);
        foreach (['weekdayStart' => 'Будні: початок', 'weekdayEnd' => 'Будні: завершення', 'weekendStart' => 'Вихідні: початок', 'weekendEnd' => 'Вихідні: завершення'] as $name => $label) {
            $builder->add($name, TimeType::class, ['label' => $label, 'widget' => 'single_text', 'input' => 'datetime_immutable']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pair::class, 'csrf_token_id' => 'pair']);
    }
}
