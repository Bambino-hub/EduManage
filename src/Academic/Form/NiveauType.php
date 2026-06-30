<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\Cycle;
use App\Academic\Entity\Niveau;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NiveauType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr'  => ['placeholder' => '6ème'],
            ])
            ->add('serie', TextType::class, [
                'label'    => 'Série (lycée uniquement)',
                'required' => false,
                'attr'     => ['placeholder' => 'ex. A4, C, D'],
                'help'     => 'Laisser vide pour le collège.',
            ])
            ->add('ordre', IntegerType::class, [
                'label' => 'Ordre d\'affichage',
                'help'  => 'Nombre entier pour trier les niveaux (1 = premier).',
                'attr'  => ['min' => 1],
            ])
            ->add('cycle', EntityType::class, [
                'label'        => 'Cycle',
                'class'        => Cycle::class,
                'choice_label' => 'nom',
                'placeholder'  => '— Choisir un cycle —',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Niveau::class]);
    }
}
