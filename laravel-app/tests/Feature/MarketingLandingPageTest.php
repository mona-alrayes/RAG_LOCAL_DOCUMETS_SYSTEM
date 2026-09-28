<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingLandingPageTest extends TestCase
{
    public function test_landing_page_matches_the_approved_dark_cyan_structure(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('data-landing-reference="approved-dark-cyan"', false)
            ->assertSee('data-landing-hero', false)
            ->assertSee('data-landing-features', false)
            ->assertSee('data-landing-workflow', false)
            ->assertSee('data-landing-processing', false)
            ->assertSee('data-landing-hero-visual', false)
            ->assertSee('data-landing-processing-visual', false)
            ->assertSee('marketing-shell marketing-shell--fluid', false)
            ->assertSee('fonts/filament/filament/inter/index.css', false)
            ->assertDontSee('images/landing/hero-document-workbench.png', false)
            ->assertDontSee('images/landing/processing-paths.png', false)
            ->assertSee('اسأل مستنداتك')
            ->assertSee('واحصل على إجابات مدعومة بالمصادر')
            ->assertSee('من المستند إلى الإجابة في أربع مراحل')
            ->assertSee('مسارات معالجة تناسب بيئة التشغيل')
            ->assertDontSee('استخلص المعرفة من وثائقك')
            ->assertDontSee('بثقة ودقة');

        $response
            ->assertSee('تمثيل بصري لواجهة الاستعلام عن الوثائق')
            ->assertSee('تمثيل بصري لمسارات المعالجة المحلية والسحابية');
    }
}
