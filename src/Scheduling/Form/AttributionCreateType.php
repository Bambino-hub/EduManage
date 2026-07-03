<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Repository\MatiereRepository;
use App\Staff\Entity\Enseignant;
use App\Staff\Service\SpecialiteMatiereMatcher;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Formulaire de création d'attribution(s) : un enseignant peut enseigner la même
 * matière dans plusieurs classes, donc on sélectionne les classes en une fois plutôt
 * que de répéter le formulaire. Pas lié à l'entité Attribution (data_class absent) —
 * le contrôleur crée une Attribution par classe sélectionnée.
 */
class AttributionCreateType extends AbstractType
{
    public function __construct(
        private readonly SpecialiteMatiereMatcher $matiereMatcher,
        private readonly MatiereRepository $matiereRepo,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $matieres = $this->matiereRepo->findAll();

        $builder
            ->add('enseignant', EntityType::class, [
                'label'        => 'Enseignant',
                'class'        => Enseignant::class,
                'choice_label' => fn (Enseignant $e) => $e->getNomComplet(),
                'placeholder'  => '— Choisir un enseignant —',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('e')
                    ->where('e.actif = true')
                    ->orderBy('e.nom', 'ASC'),
                // Pré-remplit automatiquement le champ Matière côté client (JS) une fois
                // l'enseignant choisi, en devinant sa matière depuis son champ "spécialité".
                'choice_attr' => function (Enseignant $e) use ($matieres) {
                    $matiere = $this->matiereMatcher->deviner($e->getSpecialite(), $matieres);

                    return $matiere !== null ? ['data-matiere-id' => $matiere->getId()] : [];
                },
            ])
            ->add('matiere', EntityType::class, [
                'label'        => 'Matière',
                'class'        => Matiere::class,
                'choice_label' => fn (Matiere $m) => $m->getNom().' ('.$m->getCode().')',
                'placeholder'  => '— Choisir une matière —',
            ])
            ->add('classes', EntityType::class, [
                'label'        => 'Classe(s)',
                'class'        => Classe::class,
                'choice_label' => fn (Classe $c) => $c->getNom(),
                'group_by'     => fn (Classe $c) => $c->getNiveau()->getCycle()->getNom(),
                // Une classe désactivée (niveau sans cohorte cette année) n'a besoin d'aucune
                // nouvelle attribution.
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('c')->where('c.active = true'),
                'multiple'     => true,
                'expanded'     => true,
                'constraints'  => [
                    new Count(min: 1, minMessage: 'Choisissez au moins une classe.'),
                ],
                'help' => 'Une même matière enseignée par cet enseignant dans plusieurs classes ? Cochez-les toutes ici.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
