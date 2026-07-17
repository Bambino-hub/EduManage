<?php

declare(strict_types=1);

namespace App\Exam\Form;

use App\Academic\Entity\Cycle;
use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Academic\Repository\NiveauRepository;
use App\Exam\Entity\Examen;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le champ `niveaux` n'est proposé que parmi les niveaux du cycle courant (option `cycle`,
 * fournie par le contrôleur depuis le paramètre de route) — un examen ne peut pas mélanger des
 * niveaux de collège et de lycée.
 */
class ExamenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $cycle = $options['cycle'];

        $builder
            ->add('matiere', EntityType::class, [
                'label'        => 'Matière',
                'class'        => Matiere::class,
                'choice_label' => fn(Matiere $m) => $m->getNom(),
                'placeholder'  => '— Choisir une matière —',
            ])
            ->add('date', DateType::class, [
                'label'  => 'Date',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('heureDebut', TimeType::class, [
                'label'  => 'Heure de début',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('heureFin', TimeType::class, [
                'label'  => 'Heure de fin',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('nombreSurveillantsParClasse', IntegerType::class, [
                'label' => 'Nombre de surveillants par classe',
                'attr'  => ['min' => 1, 'max' => 10],
                'help'  => 'Utilisé par la génération automatique du tableau de surveillance.',
            ])
            ->add('niveaux', EntityType::class, [
                'label'         => 'Niveaux concernés',
                'class'         => Niveau::class,
                'choice_label'  => fn(Niveau $n) => $n->getNomComplet(),
                'query_builder' => fn(NiveauRepository $repo) => $repo->createQueryBuilder('n')
                    ->where('n.cycle = :cycle')
                    ->setParameter('cycle', $cycle)
                    ->orderBy('n.ordre', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Toutes les classes actives de chaque niveau sélectionné sont concernées.',
            ])
            ->add('publie', CheckboxType::class, [
                'label'    => 'Publier la date sur le site public',
                'required' => false,
                'help'     => 'Visible dans le calendrier des examens de la page "Actualités" du site vitrine.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Examen::class]);
        $resolver->setRequired('cycle');
        $resolver->setAllowedTypes('cycle', Cycle::class);
    }
}
