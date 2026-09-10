<?php

declare(strict_types=1);

namespace App\Staff\Form;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\Sexe;
use App\Staff\Enum\TypePersonnel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Range;

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
            ])
            ->add('nbPremieresHeuresAEviter', IntegerType::class, [
                'label'       => 'Indisponible aux premières heures',
                'required'    => false,
                'empty_data'  => '0',
                'attr'        => ['min' => 0, 'max' => 4, 'placeholder' => '0'],
                'help'        => 'Nombre de premières heures de chaque jour à ne jamais lui programmer (0 = aucune contrainte). Ex : difficulté à arriver tôt après un accouchement.',
                'constraints' => [new Range(min: 0, max: 4)],
            ])
            ->add('heuresApresMidiInterdites', ChoiceType::class, [
                'label'    => 'Indisponible l\'après-midi',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices'  => [
                    '6ème heure (15h00 – 15h55)' => 6,
                    '7ème heure (15h55 – 16h50)' => 7,
                    '8ème heure (16h50 – 17h45, lycée lun./jeu.)' => 8,
                ],
                'help'     => 'Cocher les heures de l\'après-midi à ne jamais lui programmer (rien = aucune contrainte, tout = jamais l\'après-midi). Contrainte stricte appliquée à la génération de l\'emploi du temps.',
            ])
            ->add('signature', FileType::class, [
                'label'       => 'Signature (scan sur papier blanc — optionnelle)',
                'mapped'      => false,
                'required'    => false,
                'help'        => 'Apposée automatiquement sur les bulletins des classes dont il/elle est titulaire. Fond blanc retiré automatiquement.',
                'constraints' => [
                    new Image(
                        maxSize: '4M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP.',
                        maxSizeMessage: 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ])
            ->add('supprimerSignature', CheckboxType::class, [
                'label'    => 'Retirer la signature actuelle',
                'mapped'   => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Enseignant::class]);
    }
}
