<?php

declare(strict_types=1);

namespace App\Staff\Form;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\Sexe;
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
                'label'    => 'Prénom(s)',
                'required' => false,
                'attr'     => ['placeholder' => 'Edah'],
            ])
            ->add('sexe', EnumType::class, [
                'label'        => 'Sexe',
                'class'        => Sexe::class,
                'choice_label' => fn(Sexe $s) => $s->label(),
                'placeholder'  => '—',
                'required'     => false,
            ])
            ->add('email', EmailType::class, [
                'label'    => 'Adresse e-mail',
                'required' => false,
                'attr'     => ['placeholder' => 'exemple@college-adele.tg'],
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
            ->add('poste', TextType::class, [
                'label'    => 'Poste / Fonction',
                'required' => false,
                'attr'     => ['placeholder' => 'Enseignant, Censeur, Secrétaire…'],
            ])
            ->add('matricule', TextType::class, [
                'label'    => 'Matricule',
                'required' => false,
                'attr'     => ['placeholder' => 'Laisser vide si contrat privé'],
            ])
            ->add('specialite', TextType::class, [
                'label'    => 'Spécialité / Matières principales',
                'required' => false,
                'attr'     => ['placeholder' => 'Mathématiques, Physique'],
            ])
            ->add('cycle', TextType::class, [
                'label'    => 'Cycle(s)',
                'required' => false,
                'attr'     => ['placeholder' => '1, 2 ou 1/2'],
                'help'     => 'Indicatif — les affectations précises se font via les Attributions.',
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
