<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\Matiere;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MatiereType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de la matière',
                'attr'  => ['placeholder' => 'Mathématiques'],
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'MATH', 'maxlength' => 10],
                'help'  => 'Abréviation courte en majuscules (ex. MATH, FR, SVT).',
            ])
            ->add('coefficient', NumberType::class, [
                'label' => 'Coefficient',
                'scale' => 2,
                'attr'  => ['step' => '0.5', 'min' => '0.5'],
            ])
            ->add('couleur', ColorType::class, [
                'label' => 'Couleur d\'affichage',
                'help'  => 'Utilisée dans l\'emploi du temps.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Matiere::class]);
    }
}
