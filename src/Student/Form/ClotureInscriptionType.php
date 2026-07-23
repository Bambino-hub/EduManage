<?php

declare(strict_types=1);

namespace App\Student\Form;

use App\Student\Entity\Inscription;
use App\Student\Enum\MotifFinInscription;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClotureInscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateFin', DateType::class, [
                'label'  => 'Date de fin',
                'widget' => 'single_text',
            ])
            ->add('motifFin', EnumType::class, [
                'label'        => 'Motif',
                'class'        => MotifFinInscription::class,
                'choice_label' => fn(MotifFinInscription $m) => $m->label(),
                'placeholder'  => '— Choisir un motif —',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Inscription::class]);
    }
}
