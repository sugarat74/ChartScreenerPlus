<?php

namespace Tests\Feature;

use App\Http\LocalizedFrameworkMessages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API localization (app-multilanguage): the SPA's Accept-Language selects the
 * message language; status codes, response shapes, `errors` keys and Signal
 * codes never change with it.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function acceptLanguageProvider(): array
    {
        return [
            'absent header falls back to Spanish' => ['', 'es'],
            'spanish' => ['es', 'es'],
            'english' => ['en', 'en'],
            'regional english variant' => ['en-GB,en;q=0.9', 'en'],
            'regional spanish variant' => ['es-419', 'es'],
            'underscore and case' => ['EN_us', 'en'],
            'q-value order wins' => ['fr;q=1, en;q=0.8, es;q=0.5', 'en'],
            'unsupported falls back to Spanish' => ['fr-FR,de;q=0.8', 'es'],
            'invalid header falls back to Spanish' => ['***;;q=x', 'es'],
        ];
    }

    #[DataProvider('acceptLanguageProvider')]
    public function test_accept_language_resolves_to_a_supported_locale(string $header, string $expected): void
    {
        $response = $this->withHeader('Accept-Language', $header)
            ->getJson('/api/instruments/NOPE');

        $response->assertStatus(404)
            ->assertHeader('Content-Language', $expected)
            ->assertExactJson([
                'message' => trans('messages.instrument_not_found', [], $expected),
            ]);
    }

    public function test_not_found_message_is_translated_without_changing_the_shape(): void
    {
        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/instruments/NOPE')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Instrumento no encontrado.']);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/instruments/NOPE')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Instrument not found.']);
    }

    public function test_registration_validation_is_localized_with_identical_status_and_error_keys(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        $payload = ['name' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'y'];

        $spanish = $this->withHeader('Accept-Language', 'es')->postJson('/api/register', $payload);
        $english = $this->withHeader('Accept-Language', 'en')->postJson('/api/register', $payload);

        $spanish->assertStatus(422);
        $english->assertStatus(422);
        $this->assertSame(array_keys($english->json('errors')), array_keys($spanish->json('errors')));

        $spanish->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.')
            ->assertJsonPath('errors.email.0', 'El campo correo electrónico debe ser una dirección de correo válida.');
        $english->assertJsonPath('errors.name.0', 'The name field is required.')
            ->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_login_message_is_localized(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $credentials = ['email' => 'ana@example.com', 'password' => 'wrong-password'];

        $this->withHeader('Accept-Language', 'es')
            ->postJson('/api/login', $credentials)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/login', $credentials)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertGuest();
    }

    public function test_screener_parameter_errors_keep_their_keys_and_status(): void
    {
        $this->seed();

        $spanish = $this->withHeader('Accept-Language', 'es')->getJson('/api/screener?ma_cross=sideways');
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/screener?ma_cross=sideways');

        $spanish->assertStatus(422)
            ->assertJsonPath('errors.ma_cross.0', 'El parámetro ma_cross debe ser bullish o bearish.');
        $english->assertStatus(422)
            ->assertJsonPath('errors.ma_cross.0', 'The ma_cross parameter must be one of: bullish, bearish.');
    }

    public function test_successful_payloads_do_not_depend_on_the_language(): void
    {
        $this->seed();

        $spanish = $this->withHeader('Accept-Language', 'es')->getJson('/api/screener');
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/screener');

        $spanish->assertOk();
        $english->assertOk();
        $this->assertSame($english->json(), $spanish->json());
    }

    public function test_authorization_statuses_are_unchanged_in_every_language(): void
    {
        foreach (['es', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->getJson('/api/watchlist')
                ->assertStatus(401);
        }

        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user);

        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/admin/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Se requiere acceso de administrador.');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/admin/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Admin access required.');
    }

    public function test_framework_unauthenticated_message_is_translated(): void
    {
        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/watchlist')
            ->assertStatus(401)
            ->assertHeader('Content-Language', 'es')
            ->assertExactJson(['message' => 'No has iniciado sesión.']);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/watchlist')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_route_miss_is_translated_even_without_the_api_middleware_group(): void
    {
        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/does-not-exist')
            ->assertStatus(404)
            ->assertHeader('Content-Language', 'es')
            ->assertJsonPath('message', 'No se encontró el recurso solicitado.');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_login_throttle_message_is_translated_and_keeps_retry_after(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept-Language' => 'es']);
        $credentials = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertStatus(422);
        }

        $this->postJson('/api/login', $credentials)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Demasiados intentos. Espera un momento e inténtalo de nuevo.');
    }

    public function test_only_framework_default_messages_are_replaced(): void
    {
        $spanish = Request::create('/api/screeners', 'POST', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);

        $csrf = LocalizedFrameworkMessages::localize(new JsonResponse(['message' => 'CSRF token mismatch.'], 419), $spanish);
        $this->assertSame(419, $csrf->getStatusCode());
        $this->assertSame(
            ['message' => 'Tu sesión ha caducado. Recarga la página e inténtalo de nuevo.'],
            $csrf->getData(true),
        );

        $server = LocalizedFrameworkMessages::localize(new JsonResponse(['message' => 'Server Error'], 500), $spanish);
        $this->assertSame('Error del servidor.', $server->getData(true)['message']);

        $own = LocalizedFrameworkMessages::localize(
            new JsonResponse(['message' => 'Instrumento no encontrado.'], 404),
            $spanish,
        );
        $this->assertSame('Instrumento no encontrado.', $own->getData(true)['message']);

        $validation = LocalizedFrameworkMessages::localize(
            new JsonResponse(['message' => 'x', 'errors' => ['email' => ['x']]], 422),
            $spanish,
        );
        $this->assertSame(['message' => 'x', 'errors' => ['email' => ['x']]], $validation->getData(true));

        $web = LocalizedFrameworkMessages::localize(
            new JsonResponse(['message' => 'Server Error'], 500),
            Request::create('/up', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']),
        );
        $this->assertSame('Server Error', $web->getData(true)['message']);
    }

    public function test_every_supported_locale_has_the_same_catalog_keys_and_placeholders(): void
    {
        $locales = config('locales.supported');
        $this->assertContains(config('locales.default'), $locales);

        $reference = $this->catalog('en');
        $this->assertNotEmpty($reference);

        foreach ($locales as $locale) {
            $catalog = $this->catalog($locale);
            $this->assertSame(
                array_keys($reference),
                array_keys($catalog),
                "lang/{$locale} keys differ from lang/en",
            );

            foreach ($reference as $key => $line) {
                $this->assertNotSame('', trim((string) $catalog[$key]), "lang/{$locale}: {$key} is empty");
                $this->assertSame(
                    $this->placeholders((string) $line),
                    $this->placeholders((string) $catalog[$key]),
                    "lang/{$locale}: {$key} placeholders differ from lang/en",
                );
            }
        }
    }

    /** @return array<string, mixed> Flattened `file.key` => line, sorted. */
    private function catalog(string $locale): array
    {
        $lines = [];
        foreach (['auth', 'pagination', 'passwords', 'validation', 'messages'] as $file) {
            $path = lang_path("{$locale}/{$file}.php");
            $this->assertFileExists($path);
            foreach (Arr::dot(require $path) as $key => $line) {
                $lines["{$file}.{$key}"] = $line;
            }
        }
        ksort($lines);

        return $lines;
    }

    /** @return list<string> */
    private function placeholders(string $line): array
    {
        preg_match_all('/:([a-z_]+)/i', $line, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
