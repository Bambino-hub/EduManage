<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\AnneeScolaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AnneeScolaire::class]);
    }
}
