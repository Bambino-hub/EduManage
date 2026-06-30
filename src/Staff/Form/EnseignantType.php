<?php

declare(strict_types=1);

namespace App\Staff\Form;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\TypePersonnel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EnseignantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom (majuscules)',
                'attr'  => ['placeholder' => 'KOULINTE'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom(s)',
                'attr'  => ['placeholder' => 'Edah'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr'  => ['placeholder' => 'exemple@college-adele.tg'],
            ])
            ->add('telephone', TextType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => ['placeholder' => '+228 90 00 00 00', 'type' => 'tel'],
            ])
            ->add('type', EnumType::class, [
                'label'        => 'Statut',
                'class'        => TypePersonnel::class,
                'choice_label' => fn(TypePersonnel $e) => $e->label(),
            ])
            ->add('specialite', TextType::class, [
                'label'    => 'Spécialité / Matières principales',
                'required' => false,
                'attr'     => ['placeholder' => 'Mathématiques, Physique'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Enseignant actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Enseignant::class]);
    }
}
