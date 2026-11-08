<?php

namespace App\Form;

use App\Entity\LessonAttachment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class LessonAttachmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, ['label' => 'Назва матеріалу'])
            ->add('kind', ChoiceType::class, ['label' => 'Тип матеріалу', 'choices' => ['Конспект лекції' => 'note', 'Посилання' => 'link', 'Файл' => 'file']])
            ->add('content', TextareaType::class, ['label' => 'Текст конспекту', 'required' => false, 'attr' => ['rows' => 12]])
            ->add('url', UrlType::class, ['label' => 'Посилання (https:// або http://)', 'required' => false])
            ->add('upload', FileType::class, ['label' => 'Файл', 'mapped' => false, 'required' => false,
                'help' => 'До 16 МБ. Документи, презентації, таблиці, зображення, ZIP, MP3 або MP4. При редагуванні залиште порожнім, щоб зберегти поточний файл.',
                'constraints' => [new File(maxSize: '16M', extensions: ['pdf', 'txt', 'md', 'doc', 'docx', 'odt', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'mp3', 'mp4'])],
            ]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => LessonAttachment::class, 'csrf_token_id' => 'lesson_material']); }
}
