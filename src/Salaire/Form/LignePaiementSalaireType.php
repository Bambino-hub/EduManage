<?php

declare(strict_types=1);

namespace App\Salaire\Form;

use App\Salaire\Entity\LignePaiementSalaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LignePaiementSalaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Nature du versement',
                'attr'  => ['placeholder' => 'Salaire juin, Avance, Prime...'],
            ])
            ->add('montant', IntegerType::class, [
                'label' => 'Montant versé (FCFA)',
                'attr'  => ['min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LignePaiementSalaire::class]);
    }
}
