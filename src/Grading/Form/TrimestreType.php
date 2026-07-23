<?php

declare(strict_types=1);

namespace App\Grading\Form;

use App\Academic\Entity\AnneeScolaire;
use App\Grading\Entity\Trimestre;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TrimestreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('anneeScolaire', EntityType::class, [
                'label'        => 'Année scolaire',
                'class'        => AnneeScolaire::class,
                'choice_label' => 'libelle',
                'placeholder'  => '— Choisir une année —',
            ])
            ->add('numero', ChoiceType::class, [
                'label'   => 'Numéro',
                'choices' => ['1er trimestre' => 1, '2ème trimestre' => 2, '3ème trimestre' => 3],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => '1er trimestre'],
            ])
            ->add('dateDebut', DateType::class, [
                'label'  => 'Date de début',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label'  => 'Date de fin',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('nbInterrogations', IntegerType::class, [
                'label' => 'Nombre d\'interrogations',
                'attr'  => ['min' => 0],
            ])
            ->add('nbDevoirs', IntegerType::class, [
                'label' => 'Nombre de devoirs',
                'attr'  => ['min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Trimestre::class]);
    }
}
