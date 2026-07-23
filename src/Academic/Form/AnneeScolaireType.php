<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\AnneeScolaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AnneeScolaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => '2025-2026'],
                'help'  => 'Format : AAAA-AAAA (ex. 2025-2026)',
            ])
            ->add('dateDebut', DateType::class, [
                'label'  => 'Date de début',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label'  => 'Date de fin',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('active', CheckboxType::class, [
                'label'    => 'Marquer comme année active',
                'required' => false,
                'help'     => 'Une seule année peut être active à la fois.',
            ])
            ->add('poidsInterrogation', NumberType::class, [
                'label' => 'Poids des interrogations',
                'scale' => 2,
                'attr'  => ['step' => '0.05', 'min' => '0'],
                'help'  => 'Pas obligé de sommer à 1 avec les autres poids — la moyenne est renormalisée (poids égaux = moyenne simple).',
            ])
            ->add('poidsDevoirs', NumberType::class, [
                'label' => 'Poids des devoirs',
                'scale' => 2,
                'attr'  => ['step' => '0.05', 'min' => '0'],
            ])
            ->add('poidsComposition', NumberType::class, [
                'label' => 'Poids de la composition',
                'scale' => 2,
                'attr'  => ['step' => '0.05', 'min' => '0'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AnneeScolaire::class]);
    }
}
