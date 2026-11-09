<?php

namespace Tests\Feature;

use App\Http\Controllers\AttachmentController;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfViewerTest extends TestCase
{
    use RefreshDatabase;

    private function attachment(string $name = 'Neural-Concept_CV_pierre-ollivier_tissier.pdf', string $mime = 'application/pdf')
    {
        Storage::fake('local');
        Storage::disk('local')->put('candidatures/1/cv.pdf', "%PDF-1.4\n%%EOF");
        $application = Opportunity::factory()->create(['company' => 'Neural Concept'])->application()->create(['status' => 'brouillon']);

        return $application->attachments()->create(['name' => $name, 'path' => 'candidatures/1/cv.pdf', 'mime' => $mime, 'size' => 15]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    public function test_viewer_and_pdf_require_login(): void
    {
        $a = $this->attachment();

        $this->get('/pieces-jointes/'.$a->id.'/consulter')->assertRedirect('/login');
        $this->get('/pieces-jointes/'.$a->id.'/pdf')->assertRedirect('/login');
    }

    public function test_owner_reads_the_pdf_in_the_hosted_viewer(): void
    {
        $a = $this->attachment();
        $this->actingAs($this->owner());

        $url = AttachmentController::viewerUrl($a);
        $this->assertStringContainsString('?file=%2Fpieces-jointes%2F'.$a->id.'%2Fpdf', $url);

        $this->get($url)->assertOk()
            ->assertSee('<base href="'.asset('pdfjs/web').'/" />', false)
            ->assertSee('<title>Neural-Concept_CV_pierre-ollivier_tissier.pdf</title>', false)
            ->assertSee('jobs-config.js', false)
            ->assertDontSee('src="http', false);

        $pdf = $this->get('/pieces-jointes/'.$a->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline; filename=', $pdf->headers->get('content-disposition'));
        $this->assertStringContainsString('Neural-Concept_CV_pierre-ollivier_tissier.pdf', $pdf->headers->get('content-disposition'));

        $this->get('/opportunites/'.$a->application->opportunity_id)->assertOk()->assertSee('Consulter');

        foreach (['build/pdf.mjs', 'build/pdf.worker.mjs', 'web/viewer.mjs', 'web/viewer.css', 'web/jobs-config.js', 'web/locale/fr/viewer.ftl', '.htaccess'] as $file) {
            $this->assertFileExists(public_path('pdfjs/'.$file));
        }
    }

    public function test_only_pdfs_open_in_the_viewer(): void
    {
        $a = $this->attachment('Lettre.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->actingAs($this->owner());

        $this->get('/pieces-jointes/'.$a->id.'/consulter')->assertNotFound();
        $this->get('/pieces-jointes/'.$a->id.'/pdf')->assertNotFound();
    }
}
