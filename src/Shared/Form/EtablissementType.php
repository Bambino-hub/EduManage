<?php

declare(strict_types=1);

namespace App\Shared\Form;

use App\Shared\Entity\Etablissement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class EtablissementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomChefEtablissement', TextType::class, [
                'label' => 'Nom du chef d\'établissement',
                'attr'  => ['placeholder' => 'Sr. M. Epiphanie KOYE'],
            ])
            ->add('titreChefEtablissement', TextType::class, [
                'label' => 'Titre / Fonction',
                'attr'  => ['placeholder' => 'Directrice'],
            ])
            ->add('cachet', FileType::class, [
                'label'       => 'Cachet de l\'établissement (scan sur papier blanc)',
                'mapped'      => false,
                'required'    => false,
                'help'        => 'Apposé automatiquement sur les bulletins. Fond blanc retiré automatiquement.',
                'constraints' => [
                    new Image(
                        maxSize: '4M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP.',
                        maxSizeMessage: 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ])
            ->add('supprimerCachet', CheckboxType::class, [
                'label'    => 'Retirer le cachet actuel',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('signatureChefEtablissement', FileType::class, [
                'label'       => 'Signature du chef d\'établissement (scan sur papier blanc)',
                'mapped'      => false,
                'required'    => false,
                'help'        => 'Apposée automatiquement sur les bulletins. Fond blanc retiré automatiquement.',
                'constraints' => [
                    new Image(
                        maxSize: '4M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP.',
                        maxSizeMessage: 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ])
            ->add('supprimerSignatureChefEtablissement', CheckboxType::class, [
                'label'    => 'Retirer la signature actuelle',
                'mapped'   => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Etablissement::class]);
    }
}
