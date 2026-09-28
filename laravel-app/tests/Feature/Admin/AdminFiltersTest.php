<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Pages\ListDocuments;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class AdminFiltersTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_failed_and_infected_filters_exclude_other_documents(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $failed = $this->document('failed');
        $infected = $this->document('infected');
        $ready = $this->document();
        Livewire::test(ListDocuments::class)->filterTable('status', 'failed')->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$infected, $ready]);
        Livewire::test(ListDocuments::class)->filterTable('status', 'infected')->assertCanSeeTableRecords([$infected])->assertCanNotSeeTableRecords([$failed, $ready]);
    }
}
