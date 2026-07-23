<?php

declare(strict_types=1);

namespace App\ExamenNational\Form;

use App\ExamenNational\Enum\TypeExamenNational;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

class ExamenNationalUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'label'        => 'Examen',
                'class'        => TypeExamenNational::class,
                'choice_label' => fn (TypeExamenNational $t) => $t->label(),
                'placeholder'  => '— Choisir —',
                'mapped'       => false,
                'constraints'  => [new NotNull(message: 'Choisissez le type d\'examen.')],
            ])
            ->add('fichier', FileType::class, [
                'label'       => 'Relevé de notes scanné (PDF, une page par candidat)',
                'mapped'      => false,
                'constraints' => [
                    new File(
                        // Une classe entière (ex. 150 candidats de 3ème) peut peser plusieurs
                        // centaines de Mo une fois scannée — voir php.ini (upload_max_filesize/
                        // post_max_size) qui doit rester au-dessus de cette valeur.
                        maxSize: '250M',
                        mimeTypes: ['application/pdf'],
                        mimeTypesMessage: 'Le fichier doit être un PDF.',
                    ),
                ],
            ]);
    }
}
