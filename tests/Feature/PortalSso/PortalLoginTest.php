<?php

namespace Tests\Feature\PortalSso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalLoginTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
    }

    public function test_redirect_sends_the_browser_to_the_portal_with_pkce(): void
    {
        $response = $this->get(route('portal.redirect'));

        $response->assertRedirectContains('https://login.texasrenters.com/oauth/authorize?');
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $expected = rtrim(strtr(base64_encode(hash('sha256', session('portal.verifier'), true)), '+/', '-_'), '=');
        $this->assertSame($expected, $q['code_challenge']);
    }

    public function test_a_staff_member_is_linked_by_email_and_signed_in(): void
    {
        $woc = $this->staff('woc', ['email' => 'maria@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'Maria@TexasRenters.com', 'name' => 'Maria']);

        $this->finishPortalLogin()->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($woc);
        $this->assertSame('uuid-maria', $woc->fresh()->portal_subject);
        $this->assertSame('ref', session('portal.refresh_token'));
    }

    public function test_a_linked_staff_member_is_found_by_portal_subject_even_if_their_email_changed(): void
    {
        $woc = $this->staff('woc', ['email' => 'old@texasrenters.com', 'portal_subject' => 'uuid-maria']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

        $this->finishPortalLogin();

        $this->assertAuthenticatedAs($woc);
    }

    public function test_someone_without_a_maintenance_account_is_refused_and_nothing_is_created(): void
    {
        // Owner decision: no automatic accounts here; an admin adds the person first.
        $this->portalReturns(['sub' => 'uuid-new', 'email' => 'new@texasrenters.com', 'name' => 'New']);

        $this->finishPortalLogin()->assertForbidden()->assertSee('Ask an admin');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_vendor_with_a_company_email_is_never_linked_or_signed_in(): void
    {
        $vendor = $this->outsider('vendor', ['email' => 'crew@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-crew', 'email' => 'crew@texasrenters.com', 'name' => 'Crew']);

        $this->finishPortalLogin()->assertForbidden();

        $this->assertGuest();
        $this->assertNull($vendor->fresh()->portal_subject);
    }

    public function test_a_linked_account_that_is_no_longer_staff_is_refused(): void
    {
        $former = $this->outsider('vendor', ['email' => 'gone@texasrenters.com', 'portal_subject' => 'uuid-gone']);
        $this->portalReturns(['sub' => 'uuid-gone', 'email' => 'gone@texasrenters.com', 'name' => 'Gone']);

        $this->finishPortalLogin()->assertStatus(409);

        $this->assertGuest();
        $this->assertTrue($former->fresh()->hasRole('vendor'));
    }

    public function test_existing_roles_and_details_are_never_changed(): void
    {
        $admin = $this->staff('admin', ['email' => 'boss@texasrenters.com', 'name' => 'The Boss']);
        $this->portalReturns(['sub' => 'uuid-boss', 'email' => 'boss@texasrenters.com', 'name' => 'Someone Else']);

        $this->finishPortalLogin();

        $admin = $admin->fresh();
        $this->assertSame(['admin'], $admin->getRoleNames()->all());
        $this->assertSame('The Boss', $admin->name);
    }

    public function test_accounting_lands_on_waiting_on_payment_as_with_the_password_login(): void
    {
        $this->staff('accounting', ['email' => 'pay@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-pay', 'email' => 'pay@texasrenters.com', 'name' => 'Pay']);

        $this->finishPortalLogin()->assertRedirect(route('work_orders.waiting_on_payment'));
    }

    public function test_a_wrong_state_is_refused_before_any_token_request(): void
    {
        Http::fake();
        $this->startPortalLogin();

        $this->get(route('portal.callback', ['code' => 'c', 'state' => 'forged']))->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_state_cannot_be_replayed(): void
    {
        $this->staff('woc', ['email' => 'maria@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'M']);
        $state = $this->startPortalLogin();
        $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]));
        auth()->logout();

        $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();
    }

    public function test_someone_the_portal_has_not_given_this_app_is_refused(): void
    {
        $this->staff('woc', ['email' => 'x@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-x', 'email' => 'x@texasrenters.com', 'name' => 'X', 'apps' => ['cma']]);

        $this->finishPortalLogin()->assertForbidden();

        $this->assertGuest();
    }

    public function test_an_email_already_linked_to_another_portal_person_is_refused(): void
    {
        $this->staff('woc', ['email' => 'maria@texasrenters.com', 'portal_subject' => 'uuid-original']);
        $this->portalReturns(['sub' => 'uuid-other', 'email' => 'maria@texasrenters.com', 'name' => 'M']);

        $this->finishPortalLogin()->assertStatus(409);

        $this->assertGuest();
    }

    public function test_an_email_that_only_matches_under_the_database_collation_is_never_linked(): void
    {
        $lookalike = $this->staff('woc', ['email' => 'josé@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-jose', 'email' => 'jose@texasrenters.com', 'name' => 'J']);

        $this->finishPortalLogin()->assertStatus(409);

        $this->assertGuest();
        $this->assertNull($lookalike->fresh()->portal_subject);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function untrustedEmails(): array
    {
        return [
            'unverified' => [['email' => 'maria@texasrenters.com', 'email_verified' => false]],
            'empty email' => [['email' => '', 'email_verified' => true]],
            'outside company domains' => [['email' => 'maria@gmail.com', 'email_verified' => true]],
        ];
    }

    #[DataProvider('untrustedEmails')]
    public function test_an_email_the_portal_has_not_verified_is_never_used_to_link(array $claims): void
    {
        $woc = $this->staff('woc', ['email' => 'maria@texasrenters.com']);
        $this->portalReturns(array_merge(['sub' => 'uuid-x', 'name' => 'X'], $claims));

        $this->finishPortalLogin()->assertStatus(409);

        $this->assertGuest();
        $this->assertNull($woc->fresh()->portal_subject);
    }

    public function test_staff_password_login_is_refused_while_the_portal_is_on(): void
    {
        $woc = $this->staff('woc');

        $this->post('/login', ['email' => $woc->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_password_form_never_reveals_a_staff_address_or_password(): void
    {
        $woc = $this->staff('woc');
        $vendor = $this->outsider('vendor');

        $error = function (string $email, string $password): ?string {
            $message = $this->post('/login', ['email' => $email, 'password' => $password])->getSession()->get('errors')?->first('email');
            auth()->logout();

            return $message;
        };

        $staffRight = $error($woc->email, 'password');
        $this->assertSame($staffRight, $error($woc->email, 'not-it'));
        $this->assertSame($staffRight, $error('nobody@texasrenters.com', 'password'));
        $this->assertSame($staffRight, $error($vendor->email, 'not-it'));
    }

    public function test_vendor_password_login_is_unchanged_while_the_portal_is_on(): void
    {
        $vendor = $this->outsider('vendor');

        $this->post('/login', ['email' => $vendor->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($vendor);
    }

    public function test_the_emergency_switch_brings_staff_password_login_back(): void
    {
        config(['portal.allow_password_login' => true]);
        $woc = $this->staff('woc');

        $this->post('/login', ['email' => $woc->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($woc);
    }

    public function test_with_the_portal_disabled_nothing_changes(): void
    {
        config(['portal.enabled' => false]);
        $woc = $this->staff('woc');

        $this->get(route('portal.redirect'))->assertNotFound();
        $this->post('/login', ['email' => $woc->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($woc);
    }

    public function test_the_login_page_offers_the_portal_button_and_keeps_the_password_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('portalEnabled', true));
    }

    public function test_staff_who_came_through_the_portal_sign_out_back_to_the_portal(): void
    {
        $woc = $this->staff('woc');

        $this->actingAs($woc)
            ->withSession(['portal.sub' => 'uuid-1', 'portal.refresh_token' => 'r', 'portal.checked_at' => now()->getTimestamp()])
            ->post('/logout')
            ->assertRedirect('https://login.texasrenters.com/dashboard');
        $this->assertGuest();
    }

    public function test_vendors_signing_out_are_unchanged(): void
    {
        $this->actingAs($this->outsider('vendor'))->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
