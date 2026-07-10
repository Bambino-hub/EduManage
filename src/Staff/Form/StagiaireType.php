<?php

declare(strict_types=1);

namespace App\Staff\Form;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\Sexe;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire dédié aux stagiaires : volontairement plus court que
 * EnseignantType (pas d'e-mail, de matricule, de poste…), conformément
 * aux champs demandés pour un stagiaire.
 */
class StagiaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom (majuscules)',
                'attr'  => ['placeholder' => 'KOULINTE'],
            ])
            ->add('prenom', TextType::class, [
                'label'    => 'Prénom(s)',
                'required' => false,
                'attr'     => ['placeholder' => 'Edah'],
            ])
            ->add('sexe', EnumType::class, [
                'label'        => 'Sexe',
                'class'        => Sexe::class,
                'choice_label' => fn(Sexe $s) => $s->label(),
                'placeholder'  => '—',
                'required'     => false,
            ])
            ->add('telephone', TextType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => ['placeholder' => '+228 90 00 00 00', 'type' => 'tel'],
            ])
            ->add('cycle', TextType::class, [
                'label'    => 'Cycle affecté',
                'required' => false,
                'attr'     => ['placeholder' => '1, 2 ou 1/2'],
            ])
            ->add('specialite', TextType::class, [
                'label'    => 'Matière de stage',
                'required' => false,
                'attr'     => ['placeholder' => 'Mathématiques'],
            ])
            ->add('tuteur', EntityType::class, [
                'label'          => 'Encadrant',
                'class'          => Enseignant::class,
                'choice_label'   => fn(Enseignant $e) => $e->getNomComplet(),
                'query_builder'  => fn($repo) => $repo->createQueryBuilder('e')->orderBy('e.nom', 'ASC'),
                'placeholder'    => 'Aucun',
                'required'       => false,
            ])
            ->add('dateDebutStage', DateType::class, [
                'label'    => 'Date de début',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
            ])
            ->add('dateFinStage', DateType::class, [
                'label'    => 'Date de fin',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Enseignant::class]);
    }
}
