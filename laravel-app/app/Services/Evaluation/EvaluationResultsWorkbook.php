<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationRun;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EvaluationResultsWorkbook
{
    public function write(EvaluationRun $run, string $path): void
    {
        $run->loadMissing('questionResults');

        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $summary->setTitle('الملخص');

        $this->writeSummary($summary, $run);
        $this->writeRetrieval($book->createSheet(), $run);
        $this->writeAnswers($book->createSheet(), $run);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    private function writeSummary($sheet, EvaluationRun $run): void
    {
        $metrics = $run->metrics ?? [];
        $latency = $run->latency_summary ?? [];

        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', 'نتائج تقييم نظام RAG');
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A3', 'معلومات التشغيل');

        $details = [
            ['اسم التقييم', $run->name],
            ['الإصدار', $run->dataset_version],
            ['الحالة', $run->status],
            ['قيمة K', $run->k],
            ['الأسئلة المكتملة', $run->completed_questions],
            ['إجمالي الأسئلة', $run->questions_count],
        ];

        foreach ($details as $index => [$label, $value]) {
            $row = 4 + $index;
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
        }

        $sheet->setCellValue('A11', 'متوسطات جودة الاسترجاع');
        $sheet->setCellValue('A12', 'المقياس');
        $sheet->setCellValue('B12', 'المتوسط');

        $retrieval = [
            'Precision@K' => 'precision_at_k',
            'Recall@K' => 'recall_at_k',
            'Hit Rate@K' => 'hit_rate_at_k',
            'MRR@K' => 'mrr_at_k',
            'nDCG@K' => 'ndcg_at_k',
        ];

        foreach ($retrieval as $index => $key) {
            $row = 13 + array_search($index, array_keys($retrieval), true);
            $sheet->setCellValue("A{$row}", $index);
            $sheet->setCellValue("B{$row}", $metrics[$key] ?? null);
        }

        $sheet->setCellValue('A20', 'متوسطات جودة الإجابة');
        $sheet->setCellValue('A21', 'المقياس');
        $sheet->setCellValue('B21', 'المتوسط');

        $answers = [
            'صحة الإجابة' => 'correctness',
            'الالتزام بالمصادر' => 'faithfulness',
            'ارتباط الإجابة' => 'answer_relevance',
            'دقة الامتناع' => 'abstention_accuracy',
        ];

        foreach ($answers as $index => $key) {
            $row = 22 + array_search($index, array_keys($answers), true);
            $sheet->setCellValue("A{$row}", $index);
            $sheet->setCellValue("B{$row}", $metrics[$key] ?? null);
        }

        $sheet->setCellValue('A28', 'متوسط الزمن الكلي (ms)');
        $sheet->setCellValue('B28', $latency['mean_ms'] ?? null);
        $sheet->setCellValue('A29', 'الزمن الكلي P95 (ms)');
        $sheet->setCellValue('B29', $latency['p95_ms'] ?? null);

        $this->styleSheet($sheet, ['A1:B1', 'A3:B3', 'A11:B11', 'A20:B20'], ['A12:B12', 'A21:B21']);
        $sheet->getStyle('B13:B17')->getNumberFormat()->setFormatCode('0.0000');
        $sheet->getStyle('B22:B25')->getNumberFormat()->setFormatCode('0.0000');
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(28);
    }

    private function writeRetrieval($sheet, EvaluationRun $run): void
    {
        $sheet->setTitle('جودة الاسترجاع');
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', 'جودة الاسترجاع لكل سؤال');

        $headers = ['التسلسل', 'معرف السؤال', 'السؤال', 'الحالة', 'القسم', 'التصنيف', 'قابل للإجابة', 'Precision@K', 'Recall@K', 'Hit Rate@K', 'MRR@K', 'nDCG@K', 'زمن الاسترجاع (ms)'];
        $sheet->fromArray($headers, null, 'A3');

        foreach ($run->questionResults as $index => $result) {
            $row = $index + 4;
            $sheet->fromArray([
                $result->sequence, $result->question_id, $result->question, $result->status,
                $result->split, $result->category, $result->is_answerable ? 'نعم' : 'لا',
                $result->precision_at_k, $result->recall_at_k, $result->hit_rate_at_k,
                $result->mrr_at_k, $result->ndcg_at_k, $result->retrieval_ms,
            ], null, "A{$row}");
        }

        $lastRow = max(4, $run->questionResults->count() + 3);
        $this->styleTable($sheet, $lastRow, 'M');
        $sheet->getStyle("H4:L{$lastRow}")->getNumberFormat()->setFormatCode('0.0000');
    }

    private function writeAnswers($sheet, EvaluationRun $run): void
    {
        $sheet->setTitle('جودة الإجابة');
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', 'جودة الإجابة لكل سؤال');

        $headers = ['التسلسل', 'معرف السؤال', 'السؤال', 'الحالة', 'القسم', 'التصنيف', 'قابل للإجابة', 'الإجابة المرجعية', 'إجابة النظام', 'الصحة', 'الالتزام بالمصادر', 'الارتباط بالسؤال', 'الامتناع صحيح', 'زمن التوليد (ms)', 'زمن التحكيم (ms)', 'الزمن الكلي (ms)'];
        $sheet->fromArray($headers, null, 'A3');

        foreach ($run->questionResults as $index => $result) {
            $row = $index + 4;
            $sheet->fromArray([
                $result->sequence, $result->question_id, $result->question, $result->status,
                $result->split, $result->category, $result->is_answerable ? 'نعم' : 'لا',
                $result->reference_answer, $result->generated_answer, $result->correctness,
                $result->faithfulness, $result->answer_relevance,
                $result->abstention_correct === null ? null : ($result->abstention_correct ? 'نعم' : 'لا'),
                $result->generation_ms, $result->judge_ms, $result->total_ms,
            ], null, "A{$row}");
            $sheet->setCellValueExplicit("B{$row}", $result->question_id, DataType::TYPE_STRING);
        }

        $lastRow = max(4, $run->questionResults->count() + 3);
        $this->styleTable($sheet, $lastRow, 'P');
        $sheet->getStyle("J4:L{$lastRow}")->getNumberFormat()->setFormatCode('0.0000');
    }

    private function styleTable($sheet, int $lastRow, string $lastColumn): void
    {
        $this->styleSheet($sheet, ["A1:{$lastColumn}1"], ["A3:{$lastColumn}3"]);
        $sheet->setAutoFilter("A3:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A4');
        $sheet->getStyle("A3:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['C', 'H', 'I'], true) ? 45 : 18);
        }
    }

    private function styleSheet($sheet, array $titles, array $headers): void
    {
        foreach ($titles as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        }

        foreach ($headers as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF5B9BD5');
        }

        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
