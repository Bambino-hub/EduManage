<?php

declare(strict_types=1);

namespace App\Grading\Form;

use App\Grading\Entity\Bulletin;
use App\Grading\Enum\MentionConseil;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Annotations du conseil des professeurs, ajoutées après la génération du bulletin —
 * modifiables même après verrouillage puisque ce ne sont pas des valeurs recalculées.
 *
 * "Travail" (case Appréciation du professeur principal du bulletin) et les mentions
 * Félicitations/Encouragements/Tableau d'honneur sont désormais déduites automatiquement
 * de la moyenne générale (voir Grading\Twig\BulletinExtension::mentionsAutomatiques()) — ce
 * formulaire ne propose donc plus que Avertissement/Blâme, qui relèvent de la discipline et
 * ne sont pas déductibles des notes.
 */
class ComplementBulletinType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decisionConseil', TextType::class, [
                'label'    => 'Décision du conseil',
                'required' => false,
                'attr'     => ['placeholder' => 'ex. Passe en 3ème'],
            ])
            ->add('mentions', EnumType::class, [
                'label'        => 'Mentions du conseil (disciplinaires)',
                'class'        => MentionConseil::class,
                'choice_label' => fn (MentionConseil $m) => $m->label(),
                'choices'      => [MentionConseil::AVERTISSEMENT, MentionConseil::BLAME],
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'help'         => 'Félicitations, Encouragements et Tableau d\'honneur sont ajoutés automatiquement selon la moyenne générale.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Bulletin::class]);
    }
}
