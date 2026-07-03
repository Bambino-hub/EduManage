<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\MatiereNiveau;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class MatiereNiveauType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coefficient', NumberType::class, [
                'label'       => false,
                'scale'       => 2,
                'attr'        => ['step' => '0.5', 'min' => '0.5', 'class' => 'form-control form-control-sm'],
                'constraints' => [
                    new NotBlank(),
                    new GreaterThan(0),
                ],
            ])
            ->add('heuresParSemaine', NumberType::class, [
                'label'       => false,
                'scale'       => 2,
                'attr'        => ['step' => '0.5', 'min' => '0', 'class' => 'form-control form-control-sm'],
                'constraints' => [
                    new NotBlank(),
                    new GreaterThanOrEqual(0),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MatiereNiveau::class]);
    }
}
