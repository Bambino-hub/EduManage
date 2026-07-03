<?php

declare(strict_types=1);

namespace App\Academic\Form;

use App\Academic\Entity\Matiere;
use App\Academic\Enum\GroupeOptionnel;
use App\Academic\Enum\TypeSalle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
            ->add('couleur', ColorType::class, [
                'label' => 'Couleur d\'affichage',
                'help'  => 'Utilisée dans l\'emploi du temps.',
            ])
            ->add('groupeOptionnel', EnumType::class, [
                'label'       => 'Groupe optionnel',
                'class'       => GroupeOptionnel::class,
                'choice_label' => fn(GroupeOptionnel $g) => $g->label(),
                'placeholder' => 'Aucun',
                'required'    => false,
                'help'        => 'À renseigner si cette matière se déroule en parallèle d\'une autre '
                    .'(ex. Allemand/Espagnol au choix) : les deux matières du même groupe partagent '
                    .'alors les mêmes créneaux lors de la génération de l\'emploi du temps.',
            ])
            ->add('salleRequise', EnumType::class, [
                'label'       => 'Type de salle requis',
                'class'       => TypeSalle::class,
                'choice_label' => fn(TypeSalle $t) => $t->label(),
                'placeholder' => 'Salle attitrée de la classe',
                'required'    => false,
                'help'        => 'À renseigner si cette matière nécessite une salle spécialisée '
                    .'(labo, salle info, gymnase) au lieu de la salle habituelle de la classe.',
            ])
            ->add('matiereNiveaux', CollectionType::class, [
                'entry_type'   => MatiereNiveauType::class,
                'label'        => false,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Matiere::class]);
    }
}
