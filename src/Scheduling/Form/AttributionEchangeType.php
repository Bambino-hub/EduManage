<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Scheduling\Entity\Attribution;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de l'échange ciblé de 2 attributions (AttributionEchangeService) —
 * corrige l'existant sur place, sans relancer generer()/reorganiser(). Pas lié à une
 * entité : le contrôleur résout les 2 Attribution et appelle la bonne méthode du
 * service selon `type`.
 */
class AttributionEchangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('attributionA', EntityType::class, [
                'label'        => 'Première attribution',
                'class'        => Attribution::class,
                'choice_label' => fn (Attribution $a) => (string) $a,
                'placeholder'  => '— Choisir —',
            ])
            ->add('attributionB', EntityType::class, [
                'label'        => 'Deuxième attribution',
                'class'        => Attribution::class,
                'choice_label' => fn (Attribution $a) => (string) $a,
                'placeholder'  => '— Choisir —',
            ])
            ->add('type', ChoiceType::class, [
                'label'    => 'Que faut-il échanger ?',
                'choices'  => [
                    'Les enseignants (même matière et même classe conservées sur chaque ligne)' => 'enseignants',
                    'La matière ET l\'enseignant (même classe conservée — ex. Allemand ↔ Espagnol)' => 'matiere_enseignant',
                ],
                'expanded' => true,
                'multiple' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
