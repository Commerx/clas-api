<?php

namespace App\Http\Docs;

/**
 * The OpenAPI description of this API.
 *
 * Written by hand rather than generated from annotations, because the point
 * is a document the app developer can read and test against — including the
 * notes on what changed from the old API, which no generator would know.
 *
 * Keep it beside the routes: an endpoint added without an entry here is an
 * endpoint nobody outside this repository knows about.
 */
class OpenApi
{
    public static function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CLAS API',
                'version' => '1.0.0',
                'description' => self::overview(),
            ],
            'servers' => [
                ['url' => rtrim(config('app.url'), '/'), 'description' => config('app.env')],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'The `token` returned by /security/login. '
                            .'May also be sent as a `token` query parameter for '
                            .'compatibility with existing callers.',
                    ],
                ],
                'schemas' => self::schemas(),
            ],
            'security' => [['bearer' => []]],
            'tags' => [
                ['name' => 'Security', 'description' => 'Authentication'],
                ['name' => 'Listings', 'description' => 'Land listings, availability and daylight'],
                ['name' => 'Requests', 'description' => 'Access requests and bookings'],
                ['name' => 'Notifications', 'description' => 'User notifications'],
            ],
            'paths' => self::paths(),
        ];
    }

    private static function overview(): string
    {
        return <<<'MD'
Rebuilt CLAS API. Runs on its own host; the previous Yii application continues
to serve app builds already in the field from the original domain.

### Base path

Every endpoint sits under `/v1/`, with no `/api` segment — the host already
says that. The version restarts at 1 because this is a different API from the
old `/api/v7`, not a successor to it: the two share a database but not an
envelope, a set of status codes or a set of field types, and reusing the number
would make a logged response impossible to attribute to one of them.

### Conventions

Every response uses the same envelope:

```json
{ "success": true, "error": "", "data": { } }
```

The HTTP status carries the outcome as well as the body:

| Status | Meaning |
| --- | --- |
| 200 | Success |
| 401 | Missing, unknown or mismatched token |
| 403 | Authenticated but not permitted (unconfirmed or blocked account) |
| 404 | No such record |
| 422 | Request parameters failed validation; see `errors` |

### Differences from the previous API

These are deliberate, and are why a new app build is needed.

* **Consistent types.** Ids are integers and flags are booleans everywhere.
  The old API returned `"56"` from some endpoints and `56` from others, and
  `notifications_unread_count` as a string.
* **`points` returns every point.** The old API returned only the last one,
  so a listing with 19 boundary points reported one.
* **`features` returns the listing's features**, each with `id`, `name` and
  `parent_id`. The old implementation compared list positions against ids and
  returned almost nothing.
* **`photos` is an array**, not a comma-joined string.
* **Auth failures are 401 with one message.** The old API answered 200 and used
  three different strings depending on the endpoint.
* **Geometry fields are always arrays.** The old API returned `""`, `null` or a
  single object depending on the row, and raised a 500 on some.
* **`sun-details` no longer calls external services.** Times are computed
  locally and were an hour out before, on a feature used to plan travel
  around daylight.
* **Validation errors are 422** and name the field.
* **Responses carry an allowlist of fields, not `SELECT *`.** `listings/detail`
  returns 52 fields where the old API returned 75: `claim_code`, the payment
  columns, `supersede_emails_*` and the signage internals are withheld.
  `claim_code` is the secret that lets whoever holds it take ownership of a
  listing, and it was readable for 6,131 listings by any authenticated caller.
* **Object access is checked.** `requests/detail` is visible to the requester
  and to anyone who owns or manages the listing; `listings/get-blocked-days`
  to the owner or a manager. Both answered any caller before. Where access is
  refused the answer is 404, so the existence of a record is not confirmed to
  someone who cannot see it.
MD;
    }

    private static function schemas(): array
    {
        return [
            'Envelope' => [
                'type' => 'object',
                'required' => ['success', 'error', 'data'],
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'error' => ['type' => 'string', 'description' => 'Empty when success is true.'],
                    'data' => ['description' => 'Null on failure.'],
                ],
            ],
            'Error' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/Envelope'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'errors' => [
                                'type' => 'object',
                                'description' => 'Present on 422 only: field name to messages.',
                                'additionalProperties' => [
                                    'type' => 'array', 'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'Feature' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string', 'nullable' => true],
                    'parent_id' => ['type' => 'integer', 'description' => '0 for a top-level feature.'],
                ],
            ],
            'SunDay' => [
                'type' => 'object',
                'properties' => [
                    'rise' => [
                        'type' => 'string', 'nullable' => true,
                        'description' => 'Local time, 24-hour. Null if the sun does not rise.',
                        'examples' => ['08:28:05'],
                    ],
                    'set' => [
                        'type' => 'string', 'nullable' => true,
                        'description' => 'Local time, 24-hour. Null if the sun does not set.',
                        'examples' => ['18:11:49'],
                    ],
                ],
            ],
            'GeometryEntry' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string'],
                    'value' => [
                        'type' => 'string',
                        'description' => 'Pipe-separated "lat,lng" pairs.',
                        'examples' => ['51.96,-112.69|51.94,-112.69'],
                    ],
                ],
            ],
            'RequestSummary' => [
                'type' => 'object',
                'properties' => [
                    'request_id' => ['type' => 'integer'],
                    'request_date' => ['type' => 'string', 'format' => 'date'],
                    'status' => [
                        'type' => 'string',
                        'examples' => ['Pending', 'Accepted', 'Declined', 'Checked In', 'Checked Out', 'Expired'],
                    ],
                    'reason' => ['type' => 'string', 'nullable' => true],
                    'checkedin_datetime' => ['type' => 'string', 'nullable' => true],
                    'checkedout_datetime' => ['type' => 'string', 'nullable' => true],
                    'is_user_read' => ['type' => 'boolean'],
                    'is_owner_read' => ['type' => 'boolean'],
                    'requested_at' => ['type' => 'integer', 'description' => 'Unix timestamp.'],
                    'land_id' => ['type' => 'integer'],
                    'land_name' => ['type' => 'string', 'nullable' => true],
                    'photos' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'area' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GeometryEntry']],
                    'points' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GeometryEntry']],
                ],
            ],
            'ListingSummary' => [
                'type' => 'object',
                'properties' => [
                    'land_id' => ['type' => 'integer'],
                    'land_name' => ['type' => 'string', 'nullable' => true],
                    'land_description' => ['type' => 'string', 'nullable' => true],
                    'address' => ['type' => 'string', 'nullable' => true],
                    'coordinates' => ['type' => 'string', 'nullable' => true],
                    'land_lat' => ['type' => 'string', 'nullable' => true],
                    'land_lng' => ['type' => 'string', 'nullable' => true],
                    'owner' => ['type' => 'integer'],
                    'is_rid_land' => ['type' => 'boolean'],
                    'is_first_nations' => ['type' => 'boolean'],
                    'is_open_property' => ['type' => 'boolean'],
                    'fireban_status_id' => ['type' => 'integer'],
                    'photos' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'features' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Feature']],
                ],
            ],
            'ListingWithGeometry' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ListingSummary'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'allowed_activities' => ['type' => 'string', 'nullable' => true],
                            'kml_data' => ['type' => 'boolean'],
                            'area' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GeometryEntry']],
                            'points' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GeometryEntry']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Query parameters shared by the paginated listing endpoints. */
    private static function listingParams(): array
    {
        return [
            self::param('limit', 'integer', 'Maximum rows to return; capped at 500. Omit for all.'),
            self::param('start', 'integer', 'Rows to skip.'),
            self::param('after', 'integer', 'Only listings whose updated_at is at or after this unix timestamp.'),
            self::param('user_lat', 'number', 'Caller latitude; stored on the profile when both are sent.'),
            self::param('user_lng', 'number', 'Caller longitude.'),
        ];
    }

    private static function param(string $name, string $type, string $description, bool $required = false): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => $required,
            'description' => $description,
            'schema' => ['type' => $type],
        ];
    }

    /** Keyed by status code, so it merges with failures() into `responses`. */
    /**
     * A form-encoded request body. The app posts these as
     * application/x-www-form-urlencoded, so the docs show them that way.
     *
     * @param  array<string,string>  $fields  name => description
     * @param  list<string>  $required
     */
    private static function formBody(array $fields, array $required): array
    {
        $properties = [];

        foreach ($fields as $name => $description) {
            $properties[$name] = $name === 'dates'
                ? ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date'],
                    'description' => $description]
                : ['type' => 'string', 'description' => $description];
        }

        return [
            'required' => true,
            'content' => ['application/x-www-form-urlencoded' => ['schema' => [
                'type' => 'object',
                'required' => $required,
                'properties' => $properties,
            ]]],
        ];
    }

    private static function ok(string $description, array $schema): array
    {
        return [
            '200' => [
                'description' => $description,
                'content' => ['application/json' => ['schema' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/Envelope'],
                        ['type' => 'object', 'properties' => ['data' => $schema]],
                    ],
                ]]],
            ],
        ];
    }

    private static function failures(array $codes): array
    {
        $out = [];

        foreach ($codes as $code => $description) {
            $out[(string) $code] = [
                'description' => $description,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        return $out;
    }

    private static function itemsOf(string $ref): array
    {
        return [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => $ref]]],
        ];
    }

    private static function paths(): array
    {
        $authFailures = self::failures([401 => 'Missing, unknown or mismatched token.']);
        $withNotFound = self::failures([
            401 => 'Missing, unknown or mismatched token.',
            404 => 'No such listing.',
            422 => 'Parameters failed validation.',
        ]);

        return [
            '/v1/security/login' => [
                'post' => [
                    'tags' => ['Security'],
                    'summary' => 'Log in and obtain a token',
                    'description' => 'Accepts a username or an email address in `login`. '
                        .'Existing passwords work unchanged: they are bcrypt and were '
                        .'verified compatible, so no reset is required.',
                    'security' => [],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/x-www-form-urlencoded' => ['schema' => [
                            'type' => 'object',
                            'required' => ['login', 'password', 'os'],
                            'properties' => [
                                'login' => ['type' => 'string', 'description' => 'Username or email.'],
                                'password' => ['type' => 'string', 'format' => 'password'],
                                'os' => ['type' => 'string', 'enum' => ['ios', 'android']],
                                'device_token' => ['type' => 'string', 'description' => 'Push token; replaces any existing one for this OS.'],
                            ],
                        ]]],
                    ],
                    'responses' => self::ok('The user, their subscriptions and a token.', [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string', 'nullable' => true],
                            'username' => ['type' => 'string'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'token' => ['type' => 'string', 'description' => 'Send as a bearer token.'],
                            'confirmed' => ['type' => 'boolean'],
                            'land_owner' => ['type' => 'boolean'],
                            'listings_count' => ['type' => 'integer', 'description' => 'Present only when land_owner is true.'],
                            'notifications_unread_count' => ['type' => 'integer'],
                            'today_bookings' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ]) + self::failures([
                        401 => 'Invalid login or password.',
                        403 => 'Account not activated, or deactivated.',
                        422 => 'A required field is missing.',
                    ]),
                ],
            ],

            '/v1/listings/all' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Public listings',
                'parameters' => self::listingParams(),
                'responses' => self::ok('Listing summaries, newest first.',
                    self::itemsOf('#/components/schemas/ListingSummary')) + $authFailures,
            ]],

            '/v1/listings/geofence-listings' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Listings with geofences, including boundary geometry',
                'description' => 'The busiest endpoint. The previous version returned a '
                    .'500 for any page of 13 rows or more, and returned only the last '
                    .'boundary point of each listing.',
                'parameters' => self::listingParams(),
                'responses' => self::ok('Listings with area and points.',
                    self::itemsOf('#/components/schemas/ListingWithGeometry')) + $authFailures,
            ]],

            '/v1/listings/first-nations-listings' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'First Nations listings that have a KML boundary',
                'responses' => self::ok('Identifiers and KML references.', self::itemsOf('#/components/schemas/ListingSummary'))
                    + $authFailures,
            ]],

            '/v1/listings/search' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Search listings by keyword or feature',
                'parameters' => array_merge([
                    self::param('q', 'string', 'Each whitespace-separated word is matched against name, description and address.'),
                    self::param('f', 'string', 'Matches the features list.'),
                ], self::listingParams()),
                'responses' => self::ok('Matching listings.',
                    self::itemsOf('#/components/schemas/ListingSummary')) + $authFailures,
            ]],

            '/v1/listings/detail' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'One listing in full',
                'parameters' => [self::param('id', 'integer', 'Land id.', true)],
                'responses' => self::ok('Every column, plus features, photos, geometry, kml_url and is_subscribed.', [
                    'type' => 'object',
                    'properties' => ['item' => ['$ref' => '#/components/schemas/ListingWithGeometry']],
                ]) + $withNotFound,
            ]],

            '/v1/listings/availability' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Blocked days and how full each day is',
                'description' => 'Percentages are bucketed to 25, 50, 75 or 100 because '
                    .'the client renders a fill level rather than a figure. An open '
                    .'property counts vehicles against its per-activity limits; a '
                    .'private one counts people against people_allowed.',
                'parameters' => [self::param('id', 'integer', 'Land id.', true)],
                'responses' => self::ok('Blocked dates and fill levels.', [
                    'type' => 'object',
                    'properties' => [
                        'blocked' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                        'percent_filled' => [
                            'type' => 'object',
                            'description' => 'Date to 25 | 50 | 75 | 100.',
                            'additionalProperties' => ['type' => 'integer'],
                        ],
                    ],
                ]) + $withNotFound,
            ]],

            '/v1/listings/get-blocked-days' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Every blocked day for a listing',
                'parameters' => [self::param('land_id', 'integer', 'Land id.', true)],
                'responses' => self::ok('Blocked days with any per-activity limits.', [
                    'type' => 'object',
                    'properties' => [
                        'total' => ['type' => 'integer'],
                        'blocked_days' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'blocked_date' => ['type' => 'string', 'format' => 'date'],
                                'open_property_limits' => ['type' => 'object', 'nullable' => true],
                            ],
                        ]],
                    ],
                ]) + $withNotFound,
            ]],

            '/v1/listings/sun-details' => ['get' => [
                'tags' => ['Listings'],
                'summary' => 'Sunrise and sunset for a location',
                'description' => 'Computed locally; no external service is called. Times '
                    .'are local to the location and 24-hour. `rise` or `set` is null '
                    .'where the sun does not cross the horizon that day.',
                'parameters' => [
                    self::param('lat', 'number', 'Latitude, -90 to 90.', true),
                    self::param('lng', 'number', 'Longitude, -180 to 180.', true),
                ],
                'responses' => self::ok('Today and tomorrow.', [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => ['type' => 'string', 'examples' => ['America/Edmonton']],
                        'today' => ['$ref' => '#/components/schemas/SunDay'],
                        'tomorrow' => ['$ref' => '#/components/schemas/SunDay'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Coordinates missing or out of range.',
                ]),
            ]],

            '/v1/listings/subscribe' => ['post' => [
                'tags' => ['Listings'],
                'summary' => 'Subscribe to a listing\'s notifications',
                'requestBody' => self::formBody(['id' => 'Land id.'], ['id']),
                'responses' => self::ok('The listing, with is_subscribed true.', [
                    'type' => 'object',
                    'properties' => ['item' => ['$ref' => '#/components/schemas/ListingWithGeometry']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing.',
                    409 => 'Already subscribed.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/listings/unsubscribe' => ['post' => [
                'tags' => ['Listings'],
                'summary' => 'Stop receiving a listing\'s notifications',
                'requestBody' => self::formBody(['id' => 'Land id.'], ['id']),
                'responses' => self::ok('The listing, with is_subscribed false.', [
                    'type' => 'object',
                    'properties' => ['item' => ['$ref' => '#/components/schemas/ListingWithGeometry']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing.',
                    409 => 'Not subscribed.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/listings/block-day' => ['post' => [
                'tags' => ['Listings'],
                'summary' => 'Block one day on a listing you own',
                'description' => 'Owner only. `open_property_limits` is stored '
                    .'only for open properties and is ignored otherwise.',
                'requestBody' => self::formBody([
                    'land_id' => 'Land id.',
                    'date' => 'YYYY-MM-DD.',
                    'open_property_limits' => 'Per-activity limits, open properties only.',
                ], ['land_id', 'date']),
                'responses' => [
                    '201' => [
                        'description' => 'The day is now blocked.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => ['blocked_day' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'blocked_date' => ['type' => 'string', 'format' => 'date'],
                                        ],
                                    ]],
                                ]]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    403 => 'You do not own this listing.',
                    404 => 'No such listing.',
                    409 => 'That day is already blocked.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/listings/unblock-day' => ['post' => [
                'tags' => ['Listings'],
                'summary' => 'Unblock a day on a listing you own',
                'requestBody' => self::formBody([
                    'land_id' => 'Land id.',
                    'date' => 'YYYY-MM-DD.',
                ], ['land_id', 'date']),
                'responses' => self::ok('How many entries were removed.', [
                    'type' => 'object',
                    'properties' => ['unblocked' => ['type' => 'integer']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    403 => 'You do not own this listing.',
                    404 => 'No such listing, or that day is not blocked.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/listings/block-multiple-days' => ['post' => [
                'tags' => ['Listings'],
                'summary' => 'Block several days at once',
                'description' => 'Days already blocked are reported in `skipped` '
                    .'rather than failing the request, so retrying a partially '
                    .'applied batch converges. Duplicates in `dates` are ignored. '
                    .'The inserts run in one transaction.',
                'requestBody' => self::formBody([
                    'land_id' => 'Land id.',
                    'dates' => 'Array of YYYY-MM-DD.',
                    'open_property_limits' => 'Per-activity limits, open properties only.',
                ], ['land_id', 'dates']),
                'responses' => self::ok('Which days were blocked and which already were.', [
                    'type' => 'object',
                    'properties' => [
                        'blocked' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                        'skipped' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    403 => 'You do not own this listing.',
                    404 => 'No such listing.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/unread-counts' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'How many requests await attention, on each side',
                'description' => 'A user is the requester on their own bookings and the '
                    .'owner side on listings they own or manage. Both counts are '
                    .'integers; the old API returned them as strings.',
                'responses' => self::ok('Unread counts.', [
                    'type' => 'object',
                    'properties' => [
                        'requester_unread' => ['type' => 'integer'],
                        'owner_unread' => ['type' => 'integer'],
                    ],
                ]) + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/requests/list' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'Every request the caller has made',
                'parameters' => [
                    self::param('limit', 'integer', 'Maximum rows; capped at 500.'),
                    self::param('start', 'integer', 'Rows to skip.'),
                ],
                'responses' => self::ok('Requests with their listing.',
                    self::itemsOf('#/components/schemas/RequestSummary'))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/requests/mine' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'The caller\'s requests, excluding expired ones',
                'parameters' => [
                    self::param('limit', 'integer', 'Maximum rows; capped at 500.'),
                    self::param('start', 'integer', 'Rows to skip.'),
                ],
                'responses' => self::ok('Active requests.',
                    self::itemsOf('#/components/schemas/RequestSummary'))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/requests/bookings' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'Requests against listings the caller owns or manages',
                'description' => 'Includes listings taken down, since an owner still '
                    .'needs to see requests against them. Being a land manager grants '
                    .'the same access as ownership.',
                'parameters' => [
                    self::param('limit', 'integer', 'Maximum rows; capped at 500.'),
                    self::param('start', 'integer', 'Rows to skip.'),
                ],
                'responses' => self::ok('Requests across all accessible listings.',
                    self::itemsOf('#/components/schemas/RequestSummary'))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/requests/listing-bookings' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'Requests against one listing the caller owns or manages',
                'description' => 'Answers 404 rather than 403 when the caller has no '
                    .'access, so the existence of a listing is not confirmed to '
                    .'someone who cannot see it.',
                'parameters' => [
                    self::param('id', 'integer', 'Land id.', true),
                    self::param('limit', 'integer', 'Maximum rows; capped at 500.'),
                    self::param('start', 'integer', 'Rows to skip.'),
                ],
                'responses' => self::ok('Requests for that listing.',
                    self::itemsOf('#/components/schemas/RequestSummary')) + self::failures([
                        401 => 'Missing, unknown or mismatched token.',
                        404 => 'No such listing, or no access to it.',
                        422 => 'Parameters failed validation.',
                    ]),
            ]],

            '/v1/requests/detail' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'One request in full',
                'description' => 'Visible to the requester, and to anyone who owns or '
                    .'manages the listing. **The old API checked neither and returned '
                    .'any request to any authenticated caller**, including the '
                    .'requester\'s name, phone, address, coordinates, vehicle and '
                    .'driver licence number.',
                'parameters' => [self::param('request_id', 'integer', 'Request id.', true)],
                'responses' => self::ok('The request, its listing and the requester profile.', [
                    'type' => 'object',
                    'properties' => ['item' => ['$ref' => '#/components/schemas/RequestSummary']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or no access to it.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/upcoming' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'Live bookings, plus nearby and popular listings',
                'description' => 'Three lists in one response, for the screen the app '
                    .'opens on. `items` holds accepted or checked-in bookings '
                    .'bookings from today onwards, and anything still checked in '
                    .'whatever its date. `nearby` needs coordinates and is empty '
                    .'without them; sending them also records the position on the '
                    .'profile. `popular` is the most requested public listings.',
                'parameters' => [
                    self::param('user_lat', 'number', 'Caller latitude; required for nearby.'),
                    self::param('user_lng', 'number', 'Caller longitude.'),
                    self::param('device_token', 'string', 'Push token to store for this device.'),
                    self::param('os', 'string', 'ios or android; required with device_token.'),
                ],
                'responses' => self::ok('Bookings and suggestions.', [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RequestSummary']],
                        'nearby' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ListingWithGeometry']],
                        'popular' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ListingWithGeometry']],
                    ],
                ]) + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/requests/today-bookings' => ['get' => [
                'tags' => ['Requests'],
                'summary' => 'Whether the caller has a booking on one listing today',
                'description' => 'Called at the gate. Also returns the push token stored '
                    .'for this device, which is why `os` is a parameter.',
                'parameters' => [
                    self::param('land_id', 'integer', 'Land id.', true),
                    self::param('os', 'string', 'ios or android, to look up the stored token.'),
                ],
                'responses' => self::ok('Bookings on that listing today.', [
                    'type' => 'object',
                    'properties' => [
                        'user_name' => ['type' => 'string', 'nullable' => true],
                        'items' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'request_id' => ['type' => 'integer'],
                                'status' => ['type' => 'string'],
                            ],
                        ]],
                        'device_token' => ['type' => 'string'],
                        'land_id' => ['type' => 'integer'],
                        'listing_name' => ['type' => 'string', 'nullable' => true],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/submit' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Request access to a listing',
                'description' => 'Creates one request per day between start and end '
                    .'inclusive. Each day is checked first, and the whole submission '
                    .'is refused if any day cannot take it, so a stay is never '
                    .'half-booked. Refusals are 409 and name the day and the reason: '
                    .'the listing is closed that day, it is full, only so many units '
                    .'remain for that activity, you already hold a booking there that '
                    .'day, or you have reached the limit of two requests per day. '
                    .'Guides listed on the listing are exempt from the last two; a '
                    .'caller on the supersede list skips availability entirely.',
                'requestBody' => self::formBody([
                    'land_id' => 'Land id.',
                    'reason' => 'Foot Access, Hunting, Fishing, OHV, Horse or SnowMobile.',
                    'start' => 'First day, YYYY-MM-DD.',
                    'end' => 'Last day, YYYY-MM-DD. Same as start for one day.',
                    'people_count' => 'How many people, for a private listing.',
                    'people_details' => 'Who is coming.',
                    'units_count' => 'How many vehicles, for an open property.',
                    'description' => 'Why access is wanted.',
                    'ohv_details' => 'Vehicle details, open properties.',
                    'visitors' => 'Visitor details, open properties.',
                ], ['land_id', 'reason', 'start', 'end']),
                'responses' => [
                    '201' => [
                        'description' => 'The requests created, one per day.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'request_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                        'status' => ['type' => 'string', 'examples' => ['Pending', 'Accepted']],
                                        'days' => ['type' => 'integer'],
                                    ],
                                ]]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing.',
                    409 => 'One or more days cannot take the request.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/accept' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Accept a request',
                'description' => 'Owner, or a manager whose invitation was accepted. Records the decision and marks it unread for the requester and read for the owner.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or no access to it.',
                    409 => 'The request can no longer be decided.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/decline' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Decline a request',
                'description' => 'Owner, or a manager whose invitation was accepted.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or no access to it.',
                    409 => 'The request can no longer be decided.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/cancel' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Cancel your own request',
                'description' => 'The requester only, and only while the request is Pending or Accepted.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or it is not yours.',
                    409 => 'The request is not in a state that allows this.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/checkin' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Check in to an accepted booking',
                'description' => 'The requester only. There is no date restriction: the old date check was commented out and that behaviour is preserved.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                        'checkedin_datetime' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or it is not yours.',
                    409 => 'The request is not in a state that allows this.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/checkout' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Check out of a booking',
                'description' => 'The requester only, from a Checked In booking. suspicious_activity and harvest_animal are the visitor own report at check-out, not anything held about them.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                    'suspicious_activity' => 'What the visitor reports seeing.',
                    'harvest_animal' => 'What was harvested, if anything.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                        'checkedout_datetime' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or it is not yours.',
                    409 => 'The request is not in a state that allows this.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/read' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Mark a request read',
                'description' => 'Send is_owner=1 to mark the owner side, which needs owner or manager access; otherwise the requester marks their own side.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                    'is_owner' => 'Set to 1 to mark the owner side.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],
                        'is_user_read' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or no access to it.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/requests/delete-request' => ['post' => [
                'tags' => ['Requests'],
                'summary' => 'Delete your own request',
                'description' => 'The requester only. The row is removed.',
                'requestBody' => self::formBody([
                    'request_id' => 'Request id.',
                ], ['request_id']),
                'responses' => self::ok('The request after the change.', [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer'],

                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such request, or no access to it.',
                    422 => 'Parameters failed validation.',
                ]),
            ]],

            '/v1/notifications/list' => ['get' => [
                'tags' => ['Notifications'],
                'summary' => 'Notifications for the signed-in user',
                'parameters' => [
                    self::param('limit', 'integer', 'Maximum rows.'),
                    self::param('start', 'integer', 'Rows to skip.'),
                ],
                'responses' => self::ok('Newest first.', [
                    'type' => 'object',
                    'properties' => ['items' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'message' => ['type' => 'string'],
                            'sender_id' => ['type' => 'integer'],
                            'sender' => ['type' => 'string', 'nullable' => true],
                            'type' => ['type' => 'string', 'nullable' => true],
                            'category' => ['type' => 'string', 'nullable' => true],
                            'is_read' => ['type' => 'boolean'],
                            'is_admin_notification' => ['type' => 'boolean'],
                            'created' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ]]],
                ]) + $authFailures,
            ]],

            '/v1/notifications/categories' => ['get' => [
                'tags' => ['Notifications'],
                'summary' => 'Notification categories',
                'responses' => self::ok('Active categories.', [
                    'type' => 'object',
                    'properties' => ['items' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'category_name' => ['type' => 'string'],
                            'parent_id' => ['type' => 'integer'],
                        ],
                    ]]],
                ]) + $authFailures,
            ]],
        ];
    }
}
