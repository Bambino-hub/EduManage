<?php

declare(strict_types=1);

namespace App\Student\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

class EleveImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label'       => 'Fichier Excel (.xlsx)',
            'mapped'      => false,
            'constraints' => [
                new File(
                    maxSize: '2M',
                    extensions: ['xlsx'],
                    extensionsMessage: 'Le fichier doit être un classeur Excel (.xlsx).',
                ),
            ],
        ]);
    }
}
