<?php

namespace Tests\Feature;

use App\Models\ClientContact;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberMessagesPdfTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NUMBER = '+15125550000';

    private const CONTACT_NUMBER = '+15125552222';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function makeJob(string $number = '7001'): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => 'Acme Rentals',
            'jobber_web_uri' => 'https://example.test/client',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '12 Oak Lane',
            'city' => 'Austin',
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => $number,
            'title' => 'Roof patch',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function message(Jobber $job, string $text, bool $outbound, array $attributes = []): JobberTextMessage
    {
        return JobberTextMessage::query()->create([
            'messages' => $text,
            'sender_number' => $outbound ? self::OUR_NUMBER : self::CONTACT_NUMBER,
            'receiver_number' => $outbound ? self::CONTACT_NUMBER : self::OUR_NUMBER,
            'jobber_id' => $job->id,
            ...$attributes,
        ]);
    }

    private function download(User $user, Jobber $job): TestResponse
    {
        return $this->actingAs($user)->postJson(route('jobber.messages.pdf', $job), [
            'our_number' => self::OUR_NUMBER,
        ]);
    }

    private function pdfText(TestResponse $response): string
    {
        return (new Parser)->parseContent($response->getContent())->getText();
    }

    public function test_staff_download_the_whole_job_thread_as_a_pdf(): void
    {
        $job = $this->makeJob();
        ClientContact::query()->create(['jobber_id' => $job->id, 'name' => 'Pat Manager', 'phone' => self::CONTACT_NUMBER]);

        $this->message($job, 'Is the roofer coming today?', outbound: false, attributes: ['created_at' => now()->subHour()]);
        $this->message($job, 'Yes, around 2pm.', outbound: true);
        foreach (range(1, 60) as $index) {
            $this->message($job, "Bulk note {$index}", outbound: true, attributes: ['created_at' => now()->subDay()]);
        }

        $response = $this->download($this->staff(), $job);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="Job-7001 messages.pdf"');

        $text = $this->pdfText($response);
        $this->assertStringContainsString('Job #7001', $text);
        $this->assertStringContainsString('12 Oak Lane, Austin', $text);
        $this->assertStringContainsString('Pat Manager (Contact)', $text);
        $this->assertStringContainsString('62 messages', $text);
        $this->assertStringContainsString('Bulk note 60', $text);
        $this->assertLessThan(
            strpos($text, 'Yes, around 2pm.'),
            strpos($text, 'Is the roofer coming today?'),
        );
    }

    public function test_messages_from_other_jobs_are_left_out(): void
    {
        $job = $this->makeJob('7001');
        $other = $this->makeJob('7002');
        $this->message($job, 'Our message', outbound: true);
        $this->message($other, 'Someone else entirely', outbound: true);

        $text = $this->pdfText($this->download($this->staff('admin'), $job)->assertOk());

        $this->assertStringContainsString('Our message', $text);
        $this->assertStringNotContainsString('Someone else entirely', $text);
    }

    public function test_undelivered_messages_are_marked(): void
    {
        $job = $this->makeJob();
        $this->message($job, 'This one bounced', outbound: true, attributes: ['twilio_status' => 'undelivered']);

        $text = $this->pdfText($this->download($this->staff('accounting'), $job)->assertOk());

        $this->assertStringContainsString('Not delivered (undelivered)', $text);
    }

    public function test_non_staff_cannot_download(): void
    {
        $job = $this->makeJob();

        foreach (['vendor', 'owner', 'tenant'] as $role) {
            $this->download($this->staff($role), $job)->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->post(route('jobber.messages.pdf', $this->makeJob()))->assertRedirect(route('login'));
    }
}
