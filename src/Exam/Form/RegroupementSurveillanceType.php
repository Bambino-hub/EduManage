<?php

declare(strict_types=1);

namespace App\Exam\Form;

use App\Academic\Entity\Classe;
use App\Academic\Repository\ClasseRepository;
use App\Exam\Entity\RegroupementSurveillance;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RegroupementSurveillanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du regroupement',
                'attr'  => ['placeholder' => 'Ex. 1ère C / 1ère D1'],
                'help'  => 'Un libellé pour vous y retrouver — sans effet sur la génération.',
            ])
            ->add('classes', EntityType::class, [
                'label'         => 'Classes concernées',
                'class'         => Classe::class,
                'choice_label'  => fn (Classe $c) => $c->getNom().' — '.$c->getAnneeScolaire()->getLibelle(),
                'group_by'      => fn (Classe $c) => $c->getAnneeScolaire()->getLibelle(),
                'query_builder' => fn (ClasseRepository $repo) => $repo->createQueryBuilder('c')
                    ->where('c.active = true')
                    ->orderBy('c.nom', 'ASC'),
                'multiple'      => true,
                'expanded'      => true,
                'by_reference'  => false,
                'help'          => 'Choisissez au moins 2 classes qui partagent la même salle pendant '
                    .'les examens, pour tous les examens confondus.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegroupementSurveillance::class]);
    }
}
