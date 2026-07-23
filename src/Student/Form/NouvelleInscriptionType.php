<?php

declare(strict_types=1);

namespace App\Student\Form;

use App\Academic\Entity\Niveau;
use App\Academic\Repository\NiveauRepository;
use App\Student\Dto\NouvelleInscriptionInput;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NouvelleInscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('eleve', EleveType::class, ['label' => false])
            ->add('niveau', EntityType::class, [
                'label'         => 'Niveau',
                'class'         => Niveau::class,
                'choice_label'  => fn(Niveau $n) => $n->getNomComplet(),
                'placeholder'   => '— Choisir un niveau —',
                'group_by'      => fn(Niveau $n) => $n->getCycle()->getNom(),
                'query_builder' => fn(NiveauRepository $repo) => $repo->createQueryBuilder('n')
                    ->orderBy('n.ordre', 'ASC'),
            ])
            ->add('dateInscription', DateType::class, [
                'label'  => 'Date d\'inscription',
                'widget' => 'single_text',
            ])
            ->add('redoublant', CheckboxType::class, [
                'label'    => 'Redoublant(e)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NouvelleInscriptionInput::class]);
    }
}
