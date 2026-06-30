<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeSalle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SalleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom / Numéro de salle',
                'attr'  => ['placeholder' => 'Salle A1'],
            ])
            ->add('capacite', IntegerType::class, [
                'label' => 'Capacité (nombre de places)',
                'attr'  => ['min' => 1],
            ])
            ->add('type', EnumType::class, [
                'label'        => 'Type de salle',
                'class'        => TypeSalle::class,
                'choice_label' => fn(TypeSalle $e) => $e->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Salle::class]);
    }
}
