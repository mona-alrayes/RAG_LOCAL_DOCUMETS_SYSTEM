@php
    $snapshot = $evaluation->config_snapshot;
    $profiles = collect($evaluation->targets_snapshot)->flatMap(fn ($target) => array_column($target['document_targets'], 'processing_profile'))->unique();
@endphp
<x-filament::section heading="إعدادات التقييم" description="الإعدادات المحفوظة وقت تشغيل هذه التجربة">
    <div class="evaluation-snapshot" dir="rtl">
        <div class="snapshot-pipeline">
            <span class="snapshot-eyebrow">مسار الاسترجاع</span>
            <div class="snapshot-stages" dir="ltr" aria-label="Dense and Sparse retrieval, RRF fusion, then reranking">
                <span>Dense + Sparse</span><span class="snapshot-arrow" aria-hidden="true">→</span>
                <span>RRF Fusion</span><span class="snapshot-arrow" aria-hidden="true">→</span>
                <span>Reranker</span>
            </div>
        </div>
        <div class="snapshot-facts">
            <div><span>النتائج المسترجعة</span><strong dir="ltr">Top {{ $snapshot['k'] }}</strong></div>
            <div><span>معامل دمج الرتب</span><strong dir="ltr">RRF {{ $snapshot['fusion_rrf_k'] }}</strong></div>
            <div><span>المرشّحون لكل وثيقة</span><strong>{{ $snapshot['k'] * $snapshot['candidate_multiplier'] }}</strong></div>
        </div>
        <div class="snapshot-models">
            @foreach(['cloud' => ['Cloud', 'سحابي'], 'hybrid_local' => ['Hybrid Local', 'محلي']] as $profile => [$name, $label])
                @if($profiles->contains($profile))
                    @php $prefix = $profile === 'cloud' ? 'cloud' : 'local'; @endphp
                    <article class="snapshot-model-card">
                        <header><h3 dir="ltr">{{ $name }}</h3><span>{{ $label }}</span></header>
                        <dl>
                            <div><dt>موديل التضمين</dt><dd dir="ltr">{{ basename($snapshot[$prefix.'_embedding']) }}</dd></div>
                            <div><dt>إعادة الترتيب</dt><dd dir="ltr">{{ basename($snapshot[$prefix.'_reranker']) }}</dd></div>
                        </dl>
                    </article>
                @endif
            @endforeach
        </div>
        <details class="snapshot-technical">
            <summary>التفاصيل التقنية وإصدار المقاييس</summary>
            <dl>
                @foreach($snapshot as $key => $value)
                    <div><dt dir="ltr">{{ $key }}</dt><dd dir="ltr">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </details>
    </div>
</x-filament::section>
