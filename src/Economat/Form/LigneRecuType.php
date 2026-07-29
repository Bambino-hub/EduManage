<?php

declare(strict_types=1);

namespace App\Economat\Form;

use App\Economat\Entity\LigneRecu;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LigneRecuType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Nature de la recette',
                'attr'  => ['placeholder' => "Tranche 1, Arriéré, Frais d'examen..."],
            ])
            ->add('montant', IntegerType::class, [
                'label' => 'Montant versé (FCFA)',
                'attr'  => ['min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LigneRecu::class]);
    }
}
