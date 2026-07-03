<?php

declare(strict_types=1);

namespace App\Scheduling\Form;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Entity\Attribution;
use App\Staff\Entity\Enseignant;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AttributionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enseignant', EntityType::class, [
                'label'        => 'Enseignant',
                'class'        => Enseignant::class,
                'choice_label' => fn(Enseignant $e) => $e->getNomComplet(),
                'placeholder'  => '— Choisir un enseignant —',
                'query_builder' => fn($repo) => $repo->createQueryBuilder('e')
                    ->where('e.actif = true')
                    ->orderBy('e.nom', 'ASC'),
            ])
            ->add('matiere', EntityType::class, [
                'label'        => 'Matière',
                'class'        => Matiere::class,
                'choice_label' => fn(Matiere $m) => $m->getNom().' ('.$m->getCode().')',
                'placeholder'  => '— Choisir une matière —',
            ]);

        // Le champ "classe" est ajouté via un listener (et non directement ci-dessus) pour
        // pouvoir garder, dans le choix, la classe déjà affectée même si elle a été désactivée
        // depuis (sinon Symfony ne la présélectionne plus et un simple "Enregistrer" sans y
        // toucher réaffecterait silencieusement l'attribution à une autre classe). Les
        // nouvelles affectations, elles, ne peuvent viser qu'une classe active.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $attribution    = $event->getData();
            $classeActuelle = $attribution instanceof Attribution ? $attribution->getClasse() : null;

            $event->getForm()->add('classe', EntityType::class, [
                'label'        => 'Classe',
                'class'        => Classe::class,
                'choice_label' => fn(Classe $c) => $c->getNom().' — '.$c->getAnneeScolaire()->getLibelle(),
                'placeholder'  => '— Choisir une classe —',
                'group_by'     => fn(Classe $c) => $c->getAnneeScolaire()->getLibelle(),
                'query_builder' => function ($repo) use ($classeActuelle) {
                    $qb = $repo->createQueryBuilder('c')->where('c.active = true');
                    if ($classeActuelle !== null && !$classeActuelle->isActive()) {
                        $qb->orWhere('c.id = :classeActuelleId')
                            ->setParameter('classeActuelleId', $classeActuelle->getId());
                    }
                    return $qb;
                },
            ]);
        });
        // volumeHoraireHebdo n'est pas un champ de formulaire : il est déduit automatiquement
        // de MatiereNiveau (matière × niveau de la classe) par le contrôleur avant la sauvegarde.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Attribution::class]);
    }
}
