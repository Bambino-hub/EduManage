<?php

declare(strict_types=1);

namespace App\Grading\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

class NoteImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label'       => 'Fiche de notes scannée (PDF, JPG ou PNG)',
            'mapped'      => false,
            'constraints' => [
                new File(
                    maxSize: '10M',
                    mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                    mimeTypesMessage: 'Le fichier doit être un PDF, JPG ou PNG.',
                ),
            ],
        ]);
    }
}
