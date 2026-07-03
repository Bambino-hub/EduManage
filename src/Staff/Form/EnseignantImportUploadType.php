<?php

declare(strict_types=1);

namespace App\Staff\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

class EnseignantImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label'       => 'Fichier (Word .docx, Excel .xlsx ou PDF)',
            'mapped'      => false,
            'constraints' => [
                new File(
                    maxSize: '2M',
                    extensions: ['docx', 'xlsx', 'pdf'],
                    extensionsMessage: 'Le fichier doit être un document Word (.docx), Excel (.xlsx) ou PDF (.pdf).',
                ),
            ],
        ]);
    }
}
