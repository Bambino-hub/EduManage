<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Scheduling\Entity\Creneau;
use App\Scheduling\Enum\JourSemaine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CreneauType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('jourSemaine', EnumType::class, [
                'label'        => 'Jour',
                'class'        => JourSemaine::class,
                'choice_label' => fn(JourSemaine $j) => $j->label(),
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
            ->add('ordre', IntegerType::class, [
                'label' => 'Ordre d\'affichage',
                'help'  => 'Détermine l\'ordre vertical dans la grille horaire.',
                'attr'  => ['min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Creneau::class]);
    }
}
