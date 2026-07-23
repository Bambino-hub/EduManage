<?php

declare(strict_types=1);

namespace App\Student\Form;

use App\Academic\Entity\Classe;
use App\Academic\Repository\ClasseRepository;
use App\Student\Entity\Inscription;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Affecte une classe à une inscription déjà rattachée à un niveau (voir InscriptionType) :
 * le choix de classe est restreint aux classes actives de ce niveau pour l'année en cours.
 */
class AffectationClasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $niveau = $options['niveau'];

        $builder->add('classe', EntityType::class, [
            'label'         => 'Classe',
            'class'         => Classe::class,
            'choice_label'  => fn(Classe $c) => $c->getNom(),
            'placeholder'   => '— Choisir une classe —',
            'query_builder' => fn(ClasseRepository $repo) => $repo->createQueryBuilder('c')
                ->join('c.anneeScolaire', 'a')
                ->where('a.active = true')
                ->andWhere('c.active = true')
                ->andWhere('c.niveau = :niveau')
                ->setParameter('niveau', $niveau)
                ->orderBy('c.nom', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Inscription::class])
            ->setRequired('niveau');
    }
}
