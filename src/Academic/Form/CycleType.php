<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\Cycle;
use App\Academic\Enum\TypeCycle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CycleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du cycle',
                'attr'  => ['placeholder' => 'ex. Collège'],
            ])
            ->add('type', EnumType::class, [
                'label'        => 'Type',
                'class'        => TypeCycle::class,
                'choice_label' => fn(TypeCycle $e) => $e->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Cycle::class]);
    }
}
