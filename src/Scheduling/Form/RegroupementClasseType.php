<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\MatiereRepository;
use App\Scheduling\Entity\RegroupementClasse;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RegroupementClasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du regroupement',
                'attr'  => ['placeholder' => 'Ex. 1ère C / 1ère D1'],
                'help'  => 'Un libellé pour vous y retrouver — sans effet sur la génération.',
            ])
            ->add('classes', EntityType::class, [
                'label'         => 'Classes concernées',
                'class'         => Classe::class,
                'choice_label'  => fn (Classe $c) => $c->getNom().' — '.$c->getAnneeScolaire()->getLibelle(),
                'group_by'      => fn (Classe $c) => $c->getAnneeScolaire()->getLibelle(),
                'query_builder' => fn (ClasseRepository $repo) => $repo->createQueryBuilder('c')
                    ->where('c.active = true')
                    ->orderBy('c.nom', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Choisissez au moins 2 classes qui doivent partager les mêmes créneaux '
                    .'pour les matières ci-dessous.',
            ])
            ->add('matieres', EntityType::class, [
                'label'         => 'Matières concernées',
                'class'         => Matiere::class,
                'choice_label'  => fn (Matiere $m) => $m->getNom().' ('.$m->getCode().')',
                'query_builder' => fn (MatiereRepository $repo) => $repo->createQueryBuilder('m')
                    ->orderBy('m.nom', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Leurs séances devront tomber au même créneau dans toutes les classes '
                    .'ci-dessus (l\'enseignant et la salle peuvent rester différents).',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegroupementClasse::class]);
    }
}
