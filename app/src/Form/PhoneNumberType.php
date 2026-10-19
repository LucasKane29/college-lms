<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PhoneNumberType extends AbstractType
{
    public function getParent(): string
    {
        return TelType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Номер телефона',
            'help' => 'Наприклад, +380 (67) 123-45-67. Можна вставити номер у форматі 0671234567. Інші міжнародні номери вводьте з + та кодом країни.',
            'attr' => [
                'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 24,
                'placeholder' => '+380 (__) ___-__-__',
                'pattern' => '(?:[+]380(?:[0-9]{9}| \u0028[0-9]{2}\u0029 [0-9]{3}-[0-9]{2}-[0-9]{2})|[+](?!380)[1-9][0-9]{7,14})',
                'data-controller' => 'phone-number',
                'data-action' => 'focus->phone-number#focus blur->phone-number#blur beforeinput->phone-number#beforeInput input->phone-number#input change->phone-number#input',
            ],
        ]);
    }
}
