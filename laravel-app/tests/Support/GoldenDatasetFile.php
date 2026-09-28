<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class GoldenDatasetFile
{
    public static function fromArray(
        array $dataset,
        string $filename = 'dataset.xlsx',
    ): UploadedFile {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $questions = [[
            'question_id',
            'question',
            'reference_answer',
            'split',
            'category',
            'is_answerable',
        ]];

        $evidence = [[
            'question_id',
            'source',
            'page',
            'section',
            'evidence_text',
        ]];

        foreach ($dataset['examples'] as $example) {
            $questions[] = [
                $example['question_id'],
                $example['question'],
                $example['reference_answer'],
                $example['split'],
                $example['category'],
                $example['is_answerable'] ? 1 : 0,
            ];

            foreach ($example['evidence'] as $item) {
                $evidence[] = [
                    $example['question_id'],
                    $item['source'],
                    $item['page'],
                    $item['section'],
                    $item['evidence_text'],
                ];
            }
        }

        foreach ([
            'Dataset' => [
                ['dataset_version'],
                [$dataset['dataset_version']],
            ],
            'Questions' => $questions,
            'Evidence' => $evidence,
        ] as $name => $rows) {
            $book->createSheet()
                ->setTitle($name)
                ->fromArray(
                    $rows,
                    null,
                    'A1',
                    true,
                );
        }

        $path = tempnam(
            sys_get_temp_dir(),
            'golden-dataset-test-',
        );

        if ($path === false) {
            throw new \RuntimeException(
                'Failed to create temporary Golden Dataset path.'
            );
        }

        try {
            (new Xlsx($book))->save($path);

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException(
                    'Failed to create test Golden Dataset.'
                );
            }
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }

        return UploadedFile::fake()
            ->createWithContent(
                $filename,
                $contents,
            );
    }
}
