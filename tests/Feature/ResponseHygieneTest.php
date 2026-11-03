<?php

namespace Tests\Feature;

use App\Http\Docs\OpenApi;
use App\Support\ApiPath;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Rules about what may reach a response, enforced rather than remembered.
 *
 * The old API's worst exposures all came from one habit: selecting every
 * column of a table straight into the response. That published `claim_code` —
 * the secret that grants ownership of a listing — for 6,131 listings, along
 * with payment identifiers and other people's email addresses, to any
 * authenticated caller.
 *
 * Fixing the endpoints one at a time does not stop the next one reintroducing
 * it. These checks read the source, so the rule holds for endpoints nobody has
 * written yet.
 */
class ResponseHygieneTest extends TestCase
{
    /**
     * `select('*')` or `select('table.*')` in a controller means the response
     * shape is whatever the schema happens to be, today and after every future
     * migration. List the columns instead.
     */
    public function test_no_controller_selects_every_column(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            $source = File::get($path);

            // ->select(...) or ->addSelect(...) containing a bare * or table.*
            preg_match_all(
                '/->(?:add)?[sS]elect\s*\(((?:[^()]|\([^()]*\))*)\)/s',
                $source,
                $matches
            );

            foreach ($matches[1] as $argument) {
                if (preg_match('/[\'"](?:[A-Za-z_][A-Za-z0-9_]*\.)?\*[\'"]/', $argument)) {
                    $offenders[] = basename($path).': '.trim(preg_replace('/\s+/', ' ', $argument));
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A controller selects every column of a table. List the columns '
                .'explicitly, so a new column does not publish itself:'],
            $offenders,
        )));
    }

    /**
     * Every authenticated route must actually carry the middleware. A route
     * added outside the guarded group is open, and nothing else would say so.
     */
    public function test_every_api_route_is_authenticated_except_login(): void
    {
        $unguarded = [];
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! ApiPath::isApiUri($uri)) {
                continue;
            }

            $checked++;

            if ($uri === 'v1/security/login') {
                continue; // issues the token the others require
            }

            if (! in_array('auth.token', $route->gatherMiddleware(), true)) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertGreaterThan(0, $checked,
            'This guard matched no routes at all, so it proved nothing. '
            .'Has the route prefix changed without ApiPath following it?');

        $this->assertSame([], $unguarded, implode("\n", array_merge(
            ['These API routes are not behind auth.token:'],
            $unguarded,
        )));
    }

    /**
     * The documentation is the contract the app developer works from, so an
     * endpoint missing from it is an endpoint nobody outside this repository
     * knows about.
     */
    public function test_every_route_appears_in_the_openapi_document(): void
    {
        $documented = array_keys(OpenApi::spec()['paths']);
        $missing = [];
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            $uri = '/'.$route->uri();

            if (! ApiPath::isApiUri($uri)) {
                continue;
            }

            $checked++;

            if (! in_array($uri, $documented, true)) {
                $missing[] = $uri;
            }
        }

        $this->assertGreaterThan(0, $checked,
            'This guard matched no routes at all, so it proved nothing. '
            .'Has the route prefix changed without ApiPath following it?');

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_merge(
            ['These routes are not in app/Http/Docs/OpenApi.php:'],
            array_unique($missing),
        )));
    }

    /**
     * A wrong path or method must answer in the envelope, not with HTML.
     *
     * This is decided by App\Support\ApiPath, which recognises
     * an API request by its version segment. There is no /api prefix to match
     * on any more, so a client that mistypes a path is exactly the caller most
     * likely to be reading the body — and the one who would get a framework
     * HTML error page if that check ever stopped matching.
     */
    public function test_an_unknown_endpoint_answers_in_the_envelope(): void
    {
        $this->getJson('/v1/listings/no-such-thing')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'error' => 'No such endpoint.']);
    }

    public function test_an_unknown_endpoint_answers_in_the_envelope_without_a_json_header(): void
    {
        // Without Accept: application/json nothing but the path says this is
        // an API call, which is the case the version-segment check exists for.
        $response = $this->get('/v1/listings/no-such-thing');

        $response->assertStatus(404);
        $this->assertSame('application/json', $response->headers->get('content-type'));
        $this->assertSame('No such endpoint.', $response->json('error'));
    }

    public function test_a_wrong_method_answers_in_the_envelope(): void
    {
        // listings/all is a GET; the old API accepted anything.
        $this->postJson('/v1/listings/all')
            ->assertStatus(405)
            ->assertJsonPath('error', 'That method is not allowed on this endpoint.');
    }

    /** A non-API path keeps the framework's own handling. */
    public function test_a_non_api_path_is_not_given_the_envelope(): void
    {
        $this->get('/no-such-page')
            ->assertStatus(404)
            ->assertDontSee('"success"', escape: false);
    }

    /** @return list<string> */
    private function controllerFiles(): array
    {
        return array_map(
            fn ($file) => $file->getPathname(),
            File::allFiles(app_path('Http/Controllers'))
        );
    }
}
