<?php

declare(strict_types=1);

namespace App\Economat\Form;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Economat\Entity\Recu;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecuType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('anneeScolaire', EntityType::class, [
                'label'         => 'Année scolaire concernée',
                'class'         => AnneeScolaire::class,
                'choice_label'  => 'libelle',
                'query_builder' => fn (AnneeScolaireRepository $repo) => $repo->createQueryBuilder('a')
                    ->orderBy('a.dateDebut', 'DESC'),
                'help' => "Par défaut l'année en cours — à changer uniquement pour régler un arriéré d'une année antérieure.",
            ])
            ->add('nomPayeur', TextType::class, [
                'label'    => 'Payeur (facultatif)',
                'required' => false,
                'attr'     => ['placeholder' => 'Nom du tuteur ou de la personne qui paie'],
            ])
            ->add('lignes', CollectionType::class, [
                'entry_type'   => LigneRecuType::class,
                'label'        => false,
                'allow_add'    => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype'    => true,
            ]);
        // eleve, inscription, numero et dateEmission sont fixés par le contrôleur, pas ici.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recu::class]);
    }
}
