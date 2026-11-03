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
     * The endpoints reachable without a token, in full.
     *
     * Written out rather than derived, so that putting a route outside the
     * guarded group is a decision someone has to record here. Each of these
     * has to be open: the app calls them before anyone has signed in.
     */
    private const PUBLIC_ROUTES = [
        'v1/security/login',                // issues the token the rest need
        'v1/security/version-check',        // called on launch, before sign-in
        'v1/security/forgot-password',      // the caller cannot sign in
        'v1/user/signup',                   // no account yet
        'v1/user/send-confirmation-code',   // reached from the sign-in screen
    ];

    /**
     * Every route must carry auth.token unless it is listed above. A route
     * added outside the guarded group is open, and nothing else would say so.
     */
    public function test_every_api_route_is_authenticated_unless_declared_public(): void
    {
        $unguarded = [];
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! ApiPath::isApiUri($uri)) {
                continue;
            }

            $checked++;

            if (in_array($uri, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            if (! in_array('auth.token', $route->gatherMiddleware(), true)) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertGreaterThan(0, $checked,
            'This guard matched no routes at all, so it proved nothing. '
            .'Has the route prefix changed without ApiPath following it?');

        $this->assertSame([], $unguarded, implode("\n", array_merge(
            ['These API routes are not behind auth.token.'],
            ['Add auth.token, or name it in PUBLIC_ROUTES if it must be open:'],
            $unguarded,
        )));
    }

    /**
     * And the list must not go stale in the other direction: an entry for a
     * route that has since been guarded, renamed or removed would quietly
     * widen the exemption for whatever takes that path next.
     */
    public function test_no_stale_entries_in_the_public_route_list(): void
    {
        $open = [];

        foreach (app('router')->getRoutes() as $route) {
            if (ApiPath::isApiUri($route->uri())
                && ! in_array('auth.token', $route->gatherMiddleware(), true)) {
                $open[] = $route->uri();
            }
        }

        $stale = array_values(array_diff(self::PUBLIC_ROUTES, $open));

        $this->assertSame([], $stale, implode("\n", array_merge(
            ['These are listed as public but no longer are open routes.'],
            ['Remove them from PUBLIC_ROUTES:'],
            $stale,
        )));
    }

    /**
     * An endpoint that sends email to an address the caller supplies, without
     * a token, is a way to have mail from this domain delivered to someone
     * who did not ask for it. Each of those must be rate limited.
     */
    public function test_public_endpoints_that_send_mail_are_rate_limited(): void
    {
        $sendsMail = [
            'v1/security/forgot-password',
            'v1/user/signup',
            'v1/user/send-confirmation-code',
        ];

        $unlimited = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array($route->uri(), $sendsMail, true)) {
                continue;
            }

            $throttled = array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'),
            );

            if ($throttled === []) {
                $unlimited[] = $route->uri();
            }
        }

        $this->assertSame([], $unlimited, implode("\n", array_merge(
            ['These open endpoints send email with no rate limit:'],
            $unlimited,
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

    /**
     * No controller may read `uid` from the request.
     *
     * `uid` is a client-supplied account id. The old API used it as the
     * lookup for the account to act on, which is how three write endpoints
     * came to accept any account the caller named. Here it is a parameter the
     * auth.token middleware cross-checks against the token and nothing else
     * is allowed to touch, so the identity a controller acts on always comes
     * from the token.
     *
     * Without this the rule holds only because the middleware rejects a
     * mismatched pair: a controller that read `uid` would still behave
     * correctly today and would be wrong the moment that check moved or an
     * endpoint was placed outside the guarded group. This makes it a
     * property of the controllers themselves.
     */
    public function test_no_controller_reads_uid_from_the_request(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            $source = File::get($path);

            if (preg_match_all("/(?:input|post|get|query)\\s*\\(\\s*['\"]uid['\"]/", $source, $m)) {
                foreach ($m[0] as $hit) {
                    $offenders[] = basename($path).': '.$hit;
                }
            }
        }

        $this->assertSame([], $offenders, implode('
', array_merge(
            ['These controllers take an account id from the request.'],
            ['The caller comes from the token: use $this->caller() or $this->userId().'],
            $offenders,
        )));
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
