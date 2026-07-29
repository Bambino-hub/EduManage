<?php

declare(strict_types=1);

namespace App\Salaire\Form;

use App\Salaire\Entity\PaiementSalaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PaiementSalaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('lignes', CollectionType::class, [
            'entry_type'   => LignePaiementSalaireType::class,
            'label'        => false,
            'allow_add'    => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype'    => true,
        ]);
        // enseignant, numero et dateEmission sont fixés par le contrôleur, pas ici.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PaiementSalaire::class]);
    }
}
