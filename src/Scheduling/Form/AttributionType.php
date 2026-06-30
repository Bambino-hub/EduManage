<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Entity\Attribution;
use App\Staff\Entity\Enseignant;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AttributionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enseignant', EntityType::class, [
                'label'        => 'Enseignant',
                'class'        => Enseignant::class,
                'choice_label' => fn(Enseignant $e) => $e->getNomComplet(),
                'placeholder'  => '— Choisir un enseignant —',
                'query_builder' => fn($repo) => $repo->createQueryBuilder('e')
                    ->where('e.actif = true')
                    ->orderBy('e.nom', 'ASC'),
            ])
            ->add('matiere', EntityType::class, [
                'label'        => 'Matière',
                'class'        => Matiere::class,
                'choice_label' => fn(Matiere $m) => $m->getNom().' ('.$m->getCode().')',
                'placeholder'  => '— Choisir une matière —',
            ])
            ->add('classe', EntityType::class, [
                'label'        => 'Classe',
                'class'        => Classe::class,
                'choice_label' => fn(Classe $c) => $c->getNom().' — '.$c->getAnneeScolaire()->getLibelle(),
                'placeholder'  => '— Choisir une classe —',
                'group_by'     => fn(Classe $c) => $c->getAnneeScolaire()->getLibelle(),
            ])
            ->add('volumeHoraireHebdo', IntegerType::class, [
                'label' => 'Heures par semaine',
                'attr'  => ['min' => 1, 'max' => 20],
                'help'  => 'Nombre de séances (créneaux) par semaine pour cette matière dans cette classe.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Attribution::class]);
    }
}
