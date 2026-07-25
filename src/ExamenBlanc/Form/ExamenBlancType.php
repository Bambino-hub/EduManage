<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Form;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Academic\Repository\MatiereRepository;
use App\Academic\Repository\NiveauRepository;
use App\ExamenBlanc\Entity\ExamenBlanc;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Contrairement à Exam\Form\ExamenType, les niveaux ne sont PAS limités à un seul cycle : un
 * examen blanc peut couvrir 3ème (collège) et 1ère/Tle (lycée) à la fois, comme demandé.
 */
class ExamenBlancType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => 'Examen Blanc N°1'],
            ])
            ->add('dateDebut', DateType::class, [
                'label'    => 'Date de début',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
            ])
            ->add('dateFin', DateType::class, [
                'label'    => 'Date de fin',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
            ])
            ->add('niveaux', EntityType::class, [
                'label'         => 'Niveaux concernés',
                'class'         => Niveau::class,
                'choice_label'  => fn (Niveau $n) => $n->getNomComplet(),
                'query_builder' => fn (NiveauRepository $repo) => $repo->createQueryBuilder('n')
                    ->join('n.cycle', 'c')
                    ->addSelect('c')
                    ->orderBy('c.id', 'ASC')
                    ->addOrderBy('n.ordre', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Toutes les classes actives de chaque niveau sélectionné sont concernées, réunies en un seul groupe par niveau (ordre alphabétique) — matières facultatives et EPS toujours exclues.',
            ])
            ->add('matieres', EntityType::class, [
                'label'         => 'Matières évaluées',
                'class'         => Matiere::class,
                'choice_label'  => fn (Matiere $m) => $m->getNom(),
                'query_builder' => fn (MatiereRepository $repo) => $repo->createQueryBuilder('m')
                    ->where('m.groupeOptionnel IS NULL')
                    ->andWhere('m.eps = false')
                    ->orderBy('m.nom', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Décochez les matières qui ne sont pas testées à cet examen (ex. Dessin, Musique, Bibliothèque) — n\'affiche que les matières ni facultatives ni EPS.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ExamenBlanc::class]);
    }
}
