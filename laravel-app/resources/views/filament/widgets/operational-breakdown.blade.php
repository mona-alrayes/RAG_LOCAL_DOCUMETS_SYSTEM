<x-filament-widgets::widget>
    <x-filament::section heading="Processing overview">
        <div class="grid gap-6 md:grid-cols-2">
            <div><h3>Documents by status</h3>
                @forelse($documents as $status => $count)
                    <p>{{ $status }}: <strong>{{ $count }}</strong></p>
                @empty <p>No documents yet.</p> @endforelse
            </div>
            <div><h3>Runs by profile / status</h3>
                @forelse($runs as $status => $count)
                    <p>{{ $status }}: <strong>{{ $count }}</strong></p>
                @empty <p>No processing runs yet.</p> @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
