<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Academic\Repository\MatiereRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
            ])
            ->add('active', CheckboxType::class, [
                'label'    => 'Classe active cette année',
                'required' => false,
                'help'     => 'Décochez si ce niveau n\'a aucun élève cette année (ex. série qui alterne d\'une '
                    .'année sur l\'autre). La classe et ses attributions sont conservées mais disparaissent des '
                    .'emplois du temps et de la vérification des attributions.',
            ])
            ->add('matieresOptionnelles', EntityType::class, [
                'label'        => 'Matières à choix suivies par cette classe',
                'class'        => Matiere::class,
                'choice_label' => fn (Matiere $m) => $m->getNom(),
                'group_by'     => fn (Matiere $m) => $m->getGroupeOptionnel()?->label(),
                'query_builder' => fn (MatiereRepository $repo) => $repo->createQueryBuilder('m')
                    ->where('m.groupeOptionnel IS NOT NULL')
                    ->orderBy('m.groupeOptionnel', 'ASC')
                    ->addOrderBy('m.nom', 'ASC'),
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'by_reference' => false,
                'help' => 'Ne concerne que les matières à choix (ex. Allemand/Espagnol). Cochez celles que cette '
                    .'classe suit réellement — utile uniquement si le niveau de cette classe en propose. Sans effet '
                    .'sinon.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Classe::class]);
    }
}
