<?php

declare(strict_types=1);

namespace App\Student\Form;

use App\Staff\Enum\Sexe;
use App\Student\Entity\Eleve;
use App\Student\Enum\StatutEleve;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class EleveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom (majuscules)',
                'attr'  => ['placeholder' => 'KOULINTE'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom(s)',
                'attr'  => ['placeholder' => 'Edah'],
            ])
            ->add('photo', FileType::class, [
                'label'       => 'Photo (optionnelle)',
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
            ])
            ->add('sexe', EnumType::class, [
                'label'        => 'Sexe',
                'class'        => Sexe::class,
                'choice_label' => fn(Sexe $s) => $s->label(),
                'placeholder'  => '—',
                'required'     => false,
            ])
            ->add('dateNaissance', DateType::class, [
                'label'    => 'Date de naissance',
                'widget'   => 'single_text',
                'required' => false,
            ])
            ->add('lieuNaissance', TextType::class, [
                'label'    => 'Lieu de naissance',
                'required' => false,
            ])
            ->add('adresse', TextType::class, [
                'label'    => 'Adresse',
                'required' => false,
            ])
            ->add('nomTuteur', TextType::class, [
                'label' => 'Nom du parent / tuteur',
            ])
            ->add('lienTuteur', TextType::class, [
                'label'    => 'Lien avec l\'élève',
                'required' => false,
                'attr'     => ['placeholder' => 'Père, Mère, Tuteur légal…'],
            ])
            ->add('telephoneTuteur', TelType::class, [
                'label' => 'Téléphone du parent / tuteur',
                'attr'  => ['placeholder' => '+228 90 00 00 00'],
            ])
            ->add('emailTuteur', EmailType::class, [
                'label'    => 'E-mail du parent / tuteur',
                'required' => false,
            ])
            ->add('statut', EnumType::class, [
                'label'        => 'Statut',
                'class'        => StatutEleve::class,
                'choice_label' => fn(StatutEleve $s) => $s->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Eleve::class]);
    }
}
