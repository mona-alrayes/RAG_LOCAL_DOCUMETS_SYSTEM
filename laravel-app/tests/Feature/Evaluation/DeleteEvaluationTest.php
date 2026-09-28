<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Resources\Pages\ListEvaluationRuns;
use App\Models\EvaluationRun;
use App\Models\User;
use App\Services\Evaluation\DeleteEvaluation;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class DeleteEvaluationTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function runRecord(string $status): EvaluationRun
    {
        Storage::fake('local');
        Storage::disk('local')->put('evaluation-datasets/test.json', '{}');

        return EvaluationRun::create(['created_by' => $this->admin()->id, 'name' => 'Test evaluation', 'dataset_version' => 'v1', 'dataset_sha256' => hash('sha256', '{}'), 'dataset_path' => 'evaluation-datasets/test.json', 'status' => $status, 'k' => 5, 'questions_count' => 1, 'targets_snapshot' => []]);
    }

    public function test_filament_deletes_completed_result_and_its_private_dataset(): void
    {
        $run = $this->runRecord('completed');
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListEvaluationRuns::class)->callTableAction('deleteEvaluation', $run)->assertHasNoTableActionErrors();
        $this->assertModelMissing($run);
        Storage::disk('local')->assertMissing($run->dataset_path);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'evaluation.delete', 'subject_id' => $run->id]);
    }

    public function test_running_result_cannot_be_deleted_by_calling_service(): void
    {
        $run = $this->runRecord('running');
        try {
            app(DeleteEvaluation::class)->execute($this->admin(), $run->id);
            $this->fail('Deleted running evaluation');
        } catch (ValidationException) {
            $this->assertModelExists($run);
            Storage::disk('local')->assertExists($run->dataset_path);
        }
    }

    public function test_regular_user_cannot_delete_history(): void
    {
        $this->expectException(AuthorizationException::class);
        app(DeleteEvaluation::class)->execute(User::factory()->create(), 1);
    }
}
