<?php

namespace Tests\Feature;

use App\Jobs\GenerateW9PdfJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The owner's W-9 must reach PropertyWare even when storage/app itself is not
 * writable by the queue worker - on the VPS it belonged to root, the signature
 * could not be written there, and every W-9 was silently lost.
 */
class GenerateW9PdfJobTest extends TestCase
{
    private const BUILDING_ID = 5421236225;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/w9-job-test-'.uniqid();
        File::makeDirectory($this->storageRoot.'/app/public', 0755, true);
        chmod($this->storageRoot.'/app', 0555);
        $this->app->useStoragePath($this->storageRoot);

        Http::preventStrayRequests();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 1], 200),
        ]);
    }

    protected function tearDown(): void
    {
        chmod($this->storageRoot.'/app', 0755);
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_w9_is_uploaded_when_storage_app_is_not_writable(): void
    {
        (new GenerateW9PdfJob($this->signature(), $this->formData(), ['id' => self::BUILDING_ID, 'name' => 'Test Building'], []))->handle();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/docs')
            && $request->isMultipart()
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'entityId' && (string) $part['contents'] === (string) self::BUILDING_ID));
    }

    public function test_w9_without_an_ein_or_3b_answer_is_still_uploaded(): void
    {
        $formData = $this->formData();
        unset($formData['w9_ein'], $formData['w9_tax_class1']);

        (new GenerateW9PdfJob($this->signature(), $formData, ['id' => self::BUILDING_ID], []))->handle();

        Http::assertSentCount(1);
    }

    public function test_no_signature_or_pdf_is_left_behind(): void
    {
        (new GenerateW9PdfJob($this->signature(), $this->formData(), ['id' => self::BUILDING_ID], []))->handle();

        $this->assertSame([], File::files($this->storageRoot.'/app/public'));
        $this->assertFileDoesNotExist($this->storageRoot.'/app/temp_signature.png');
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(40, 10);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    /**
     * @return array<string, string>
     */
    private function formData(): array
    {
        return [
            'w9_entity_name' => 'Jane Owner',
            'w9_business_name' => '',
            'w9_tax_class' => 'r1',
            'w9_tax_class1' => '',
            'w9_llc_tax_class' => '',
            'w9_other_tax_class' => '',
            'w9_exempt_payee_code' => '',
            'w9_exempt_reporting_code' => '',
            'w9_address' => '100 Main St',
            'w9_address2' => 'Houston, TX 77005',
            'w9_account_list' => '',
            'w9_requester_name_and_address' => '',
            'w9_ssn' => '123456789',
            'w9_ein' => '',
        ];
    }
}
