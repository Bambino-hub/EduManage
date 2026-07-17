<?php

declare(strict_types=1);

namespace App\Website\Form;

use App\Academic\Enum\TypeCycle;
use App\Website\Entity\Actualite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class ActualiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr'  => ['placeholder' => 'Ex. Réunion de rentrée — 3ème trimestre'],
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'Texte',
                'attr'  => ['rows' => 6],
            ])
            ->add('datePublication', DateType::class, [
                'label'  => 'Date de publication',
                'widget' => 'single_text',
            ])
            ->add('cycleConcerne', EnumType::class, [
                'label'        => 'Cycle concerné',
                'class'        => TypeCycle::class,
                'choice_label' => fn (TypeCycle $c) => $c->label(),
                'placeholder'  => 'Toute l\'école',
                'required'     => false,
            ])
            ->add('publie', CheckboxType::class, [
                'label'    => 'Publier immédiatement',
                'required' => false,
                'help'     => 'Décoché, l\'actualité reste en brouillon : invisible sur le site public.',
            ])
            ->add('image', FileType::class, [
                'label'       => 'Image (optionnelle)',
                'mapped'      => false,
                'required'    => false,
                'constraints' => [
                    new Image(
                        maxSize: '4M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP.',
                        maxSizeMessage: 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Actualite::class]);
    }
}
