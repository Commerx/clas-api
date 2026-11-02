<?php

namespace Tests\Feature;

use App\Http\Docs\OpenApi;
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

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            if ($uri === 'api/v7/security/login') {
                continue; // issues the token the others require
            }

            if (! in_array('auth.token', $route->gatherMiddleware(), true)) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

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

        foreach (app('router')->getRoutes() as $route) {
            $uri = '/'.$route->uri();

            if (! str_starts_with($uri, '/api/')) {
                continue;
            }

            if (! in_array($uri, $documented, true)) {
                $missing[] = $uri;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_merge(
            ['These routes are not in app/Http/Docs/OpenApi.php:'],
            array_unique($missing),
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
