<?php

declare(strict_types=1);

namespace App\Economat\Form;

use App\Economat\Entity\Tarif;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TarifType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('montantAnnuel', IntegerType::class, [
            'label' => 'Montant annuel dû (FCFA)',
            'attr'  => ['min' => 0],
        ]);
        // niveau et anneeScolaire ne sont pas dans le formulaire : fixés par le contrôleur.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tarif::class]);
    }
}
