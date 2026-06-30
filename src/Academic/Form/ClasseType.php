<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Niveau;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de la classe',
                'attr'  => ['placeholder' => '6ème A'],
                'help'  => 'Exemple : 6ème A, 3ème B, Tle D 1',
            ])
            ->add('effectifMax', IntegerType::class, [
                'label' => 'Effectif maximum',
                'attr'  => ['min' => 1, 'max' => 100],
            ])
            ->add('niveau', EntityType::class, [
                'label'        => 'Niveau',
                'class'        => Niveau::class,
                'choice_label' => fn(Niveau $n) => $n->getCycle()->getNom().' — '.$n->getNomComplet(),
                'placeholder'  => '— Choisir un niveau —',
                'group_by'     => fn(Niveau $n) => $n->getCycle()->getNom(),
            ])
            ->add('anneeScolaire', EntityType::class, [
                'label'        => 'Année scolaire',
                'class'        => AnneeScolaire::class,
                'choice_label' => 'libelle',
                'placeholder'  => '— Choisir une année —',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Classe::class]);
    }
}
