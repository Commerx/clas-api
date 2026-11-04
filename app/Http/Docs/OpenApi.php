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
                ['name' => 'Hunters tags', 'description' => 'The tags a hunting alert matches on'],
                ['name' => 'Chat', 'description' => 'Saved conversations with the in-app assistant'],
                ['name' => 'Reference', 'description' => 'Management units, species, seasons and regulations'],
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
            'Tag' => [
                'type' => 'object',
                'description' => 'A hunters tag: what a hunting alert matches on.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'tag_number' => ['type' => 'integer', 'nullable' => true,
                        'description' => 'Null when the tag was filed with tag_required false.'],
                    'wildlife_id_number' => ['type' => 'integer', 'nullable' => true],
                    'season_start_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'season_end_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true,
                        'description' => 'Null never expires; a date in the past excludes the '
                            .'tag from alert matching whatever status says.'],
                    'species_id' => ['type' => 'integer'],
                    'species_gender' => ['type' => 'string', 'nullable' => true],
                    'province' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['Active', 'Expired', 'Used']],
                    'created' => ['type' => 'string', 'format' => 'date-time'],
                    'updated' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                ],
            ],
            'ManagementUnit' => [
                'type' => 'object',
                'properties' => [
                    'wmu_id' => ['type' => 'integer'],
                    'province' => ['type' => 'string', 'nullable' => true],
                    'wmu_number' => ['type' => 'string', 'nullable' => true,
                        'description' => 'Not unique on its own: numbers repeat across provinces.'],
                    'wmu_name' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'FishingRegulations' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'province' => ['type' => 'string', 'nullable' => true],
                    'region' => ['type' => 'string', 'nullable' => true,
                        'description' => 'Null in every row today.'],
                    'regulations_pdf' => ['type' => 'string', 'nullable' => true],
                    'created' => ['type' => 'string', 'format' => 'date-time'],
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

    /**
     * The account block returned by login, signup and user/get.
     *
     * Only the fields a client is likely to branch on are named; the block
     * also carries the subscription flags, which are described in the
     * endpoint text rather than repeated for each one.
     */
    private static function account(bool $withProfile = false): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string', 'nullable' => true],
            'username' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'token' => ['type' => 'string', 'description' => 'Send as a bearer token.'],
            'confirmed' => ['type' => 'boolean'],
            'deactivation_date' => [
                'description' => 'When an unconfirmed account stops working, or false once confirmed.',
                'oneOf' => [['type' => 'string'], ['type' => 'boolean']],
            ],
            'send_notifications' => ['type' => 'boolean'],
            'profile_info_exists' => ['type' => 'boolean'],
            'vehicle_info_exists' => ['type' => 'boolean'],
            'free_user' => ['type' => 'boolean'],
            'subscriber' => ['type' => 'boolean'],
            'land_owner' => ['type' => 'boolean'],
            'listings_count' => ['type' => 'integer', 'description' => 'Present only when land_owner is true.'],
            'notifications_unread_count' => ['type' => 'integer'],
            'today_bookings' => ['type' => 'array', 'items' => ['type' => 'object']],
        ];

        if ($withProfile) {
            $properties = [
                'first_name' => ['type' => 'string', 'nullable' => true],
                'last_name' => ['type' => 'string', 'nullable' => true],
                'display_name' => ['type' => 'string', 'nullable' => true],
                'brief_bio' => ['type' => 'string', 'nullable' => true],
                'street' => ['type' => 'string', 'nullable' => true],
                'city' => ['type' => 'string', 'nullable' => true],
                'province' => ['type' => 'string', 'nullable' => true],
                'country' => ['type' => 'string', 'nullable' => true],
                'postal_code' => ['type' => 'string', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'occupation' => ['type' => 'string', 'nullable' => true],
                'guide' => ['type' => 'string', 'enum' => ['Yes', 'No']],
                'guide_number' => ['type' => 'string', 'nullable' => true],
                'driver_license_number' => ['type' => 'string', 'nullable' => true],
                'license_plate' => ['type' => 'string', 'nullable' => true],
                'vehicle_description' => ['type' => 'string', 'nullable' => true],
                'vehicle_year' => ['type' => 'string', 'nullable' => true],
                'vehicle_make' => ['type' => 'string', 'nullable' => true],
                'vehicle_model' => ['type' => 'string', 'nullable' => true],
                'vehilcle_color' => [
                    'type' => 'string', 'nullable' => true,
                    'description' => 'Spelled as the column is spelled.',
                ],
                'other_features' => ['type' => 'string', 'nullable' => true],
            ] + $properties;
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /** What a broadcast reports back: the row it created and who it reached. */
    private static function broadcastResult(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The notification created.'],
                'recipients' => [
                    'type' => 'integer',
                    'description' => 'How many people received a copy in their list.',
                ],
                'pushed' => [
                    'type' => 'integer',
                    'description' => 'How many devices a push was sent to. Lower than '
                        .'recipients when people have turned notifications off, or have no '
                        .'registered device.',
                ],
            ],
        ];
    }

    /** The four notification switches, as the settings endpoints report them. */
    private static function notificationFlags(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'configured' => [
                    'type' => 'boolean',
                    'description' => 'False when the user has never chosen and these are the defaults.',
                ],
                'all_notifications' => ['type' => 'boolean'],
                'wildlife_notifications' => ['type' => 'boolean'],
                'awareness_notifications' => ['type' => 'boolean'],
                'subscriber_notifications' => ['type' => 'boolean'],
            ],
        ];
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

            '/v1/security/version-check' => ['get' => [
                'tags' => ['Security'],
                'summary' => 'Whether the caller is on the current build, plus reference data',
                'description' => 'Open, because the app calls this before anyone has signed in. '
                    .'Returns the lists the app needs to render its pickers, and the prices it quotes. '
                    .'Two changes from the previous version. Versions are compared with version_compare '
                    .'rather than a string operator: the old comparison reported no update to any iOS '
                    .'client on 2.6 through 2.9 while 2.54 was shipping. And the reference lists are '
                    .'arrays, where they used to be objects keyed by their own values.',
                'security' => [],
                'parameters' => [
                    self::param('os', 'string', 'ios or android.', true),
                    self::param('version', 'string', 'The version the caller is running, such as 8.4.8.', true),
                ],
                'responses' => self::ok('Update status and reference data.', [
                    'type' => 'object',
                    'properties' => [
                        'hasUpdate' => ['type' => 'boolean'],
                        'current_version' => ['type' => 'string'],
                        'message' => [
                            'type' => 'string', 'nullable' => true,
                            'description' => 'The update message, or null when there is no update. Always present.',
                        ],
                        'alert' => [
                            'type' => 'object', 'nullable' => true,
                            'description' => 'Always null. Reserved for a service-wide notice.',
                        ],
                        'features' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'land_rules' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'booking_reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'property_request_reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'open_property_request_reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'open_property_request_activity_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'checkout_categories' => ['type' => 'object'],
                        'signage_options' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'subscription_types' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ]) + self::failures([
                    404 => 'No such app platform.',
                    422 => 'os or version missing.',
                ]),
            ]],

            '/v1/security/forgot-password' => ['post' => [
                'tags' => ['Security'],
                'summary' => 'Send a password reset link',
                'description' => 'Always reports success, whether or not the address has an account. '
                    .'The previous version returned the validation error, which said there is no user '
                    .'with this email address, so anyone could check whether an address was registered. '
                    .'The link is served by the Yii application, which owns the reset page. '
                    .'Rate limited per IP.',
                'security' => [],
                'requestBody' => self::formBody(
                    ['email' => 'The account email address.'],
                    ['email'],
                ),
                'responses' => self::ok('Accepted.', [
                    'type' => 'object',
                    'properties' => ['message' => ['type' => 'string']],
                ]) + self::failures([
                    422 => 'Not a valid email address.',
                    429 => 'Too many attempts.',
                ]),
            ]],

            '/v1/security/logout' => ['post' => [
                'tags' => ['Security'],
                'summary' => 'Stop push notifications for this device',
                'description' => 'Forgets the device push token. It does not invalidate the API token: '
                    .'auth_key is one value per account, shared with every other signed-in device and '
                    .'with the old API, so clearing it would sign the user out everywhere. Changing a '
                    .'password or deactivating does rotate it. Without device_token, every token for '
                    .'that OS is forgotten.',
                'requestBody' => self::formBody([
                    'os' => 'ios or android. Required.',
                    'device_token' => 'The push token to forget. Optional.',
                ], ['os']),
                'responses' => self::ok('How many device tokens were forgotten.', [
                    'type' => 'object',
                    'properties' => ['forgotten' => ['type' => 'integer']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'os missing or not recognised.',
                ]),
            ]],

            '/v1/user/signup' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Create an account',
                'description' => 'Creates the user and profile rows together and emails a confirmation '
                    .'link. The account works for seven days before it is confirmed. Usernames follow '
                    .'the same rule as the web application: a letter, then letters, digits or '
                    .'underscores, 3 to 20 characters. Rate limited per IP.',
                'security' => [],
                'requestBody' => self::formBody([
                    'username' => 'Required. A letter, then word characters, 3 to 20.',
                    'email' => 'Required and unique.',
                    'password' => 'Required, at least 6 characters.',
                    'first_name' => 'Required.',
                    'last_name' => 'Required.',
                    'terms_of_use' => 'Required. Must be accepted.',
                    'display_name' => 'Optional. Defaults to the full name.',
                    'guide_number' => 'Optional. Setting one marks the profile as a guide.',
                    'vehicle_description' => 'Optional.',
                ], ['username', 'email', 'password', 'first_name', 'last_name', 'terms_of_use']),
                'responses' => [
                    '201' => [
                        'description' => 'The account, with a token, plus whether the confirmation mail was sent.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => self::account()]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    422 => 'Validation failed; see errors.',
                    429 => 'Too many attempts.',
                ]),
            ]],

            '/v1/user/send-confirmation-code' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Resend the email confirmation link',
                'description' => 'Works signed in and signed out. A signed-in caller is asking about '
                    .'their own account and gets a specific answer, including 409 when it is already '
                    .'confirmed. A caller supplying only an email gets the same reply whatever the '
                    .'address turns out to be, because the previous version distinguished unknown, '
                    .'already confirmed and sent, which reported whether any address had an account. '
                    .'That version was also a GET. Rate limited per IP.',
                'security' => [],
                'requestBody' => self::formBody([
                    'email' => 'Required when no token is sent.',
                    'token' => 'Optional. When valid, the answer is about that account.',
                ], []),
                'responses' => self::ok('Accepted.', [
                    'type' => 'object',
                    'properties' => ['message' => ['type' => 'string']],
                ]) + self::failures([
                    409 => 'This account has already been confirmed. Signed-in callers only.',
                    422 => 'No token and no valid email address.',
                    429 => 'Too many attempts.',
                ]),
            ]],

            '/v1/user/get' => ['get' => [
                'tags' => ['User'],
                'summary' => 'The callers own account and profile',
                'description' => 'The account block login returns, plus the editable profile columns. '
                    .'Those are an allowlist: the previous version returned every column of the row, '
                    .'which included pipedrive_person_id, the accounts identifier in the companys CRM, '
                    .'along with the gravatar bookkeeping.',
                'responses' => self::ok('The account and profile.', self::account(withProfile: true))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/user/update-address' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Update name, address and phone',
                'description' => 'Writes to the callers own profile. The previous version took the '
                    .'profile from a uid parameter and never checked the token against it.',
                'requestBody' => self::formBody(['first_name' => 'Required.', 'last_name' => 'Required.', 'street' => 'Required.', 'city' => 'Required.', 'province' => 'Required.', 'country' => 'Required.', 'postal_code' => 'Required.', 'phone' => 'Required, 10 to 15 digits.'], [
                    'first_name', 'last_name', 'street', 'city', 'province',
                    'country', 'postal_code', 'phone',
                ]),
                'responses' => self::ok('The updated account and profile.', self::account(withProfile: true))
                    + self::failures([
                        401 => 'Missing, unknown or mismatched token.',
                        422 => 'Validation failed; see errors.',
                    ]),
            ]],

            '/v1/user/update-vehicle-info' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Update vehicle and licence details',
                'description' => 'Writes to the callers own profile. Note the column spelling '
                    .'vehilcle_color, which is kept because the database uses it.',
                'requestBody' => self::formBody([
                    'driver_license_number' => 'Required.',
                    'vehicle_description' => 'Optional.',
                    'vehicle_year' => 'Optional.',
                    'vehicle_make' => 'Optional.',
                    'vehicle_model' => 'Optional.',
                    'vehilcle_color' => 'Optional. Spelled as the column is spelled.',
                    'license_plate' => 'Optional.',
                    'other_features' => 'Optional.',
                ], ['driver_license_number']),
                'responses' => self::ok('The updated account and profile.', self::account(withProfile: true))
                    + self::failures([
                        401 => 'Missing, unknown or mismatched token.',
                        422 => 'Validation failed; see errors.',
                    ]),
            ]],

            '/v1/user/update-profile' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Update the whole profile',
                'description' => 'Both of the sets above, plus the bio fields. A recorded guide number '
                    .'is what marks a profile as a guide; clearing it clears the flag.',
                'requestBody' => self::formBody(['first_name' => 'Required.', 'last_name' => 'Required.', 'street' => 'Required.', 'city' => 'Required.', 'province' => 'Required.', 'country' => 'Required.', 'postal_code' => 'Required.', 'phone' => 'Required, 10 to 15 digits.',
                    'driver_license_number' => 'Required.',
                    'display_name' => 'Optional.',
                    'brief_bio' => 'Optional.',
                    'occupation' => 'Optional.',
                    'guide_number' => 'Optional.',
                    'vehicle_description' => 'Optional.',
                    'vehicle_year' => 'Optional.',
                    'vehicle_make' => 'Optional.',
                    'vehicle_model' => 'Optional.',
                    'vehilcle_color' => 'Optional.',
                    'license_plate' => 'Optional.',
                    'other_features' => 'Optional.',
                ], [
                    'first_name', 'last_name', 'street', 'city', 'province',
                    'country', 'postal_code', 'phone', 'driver_license_number',
                ]),
                'responses' => self::ok('The updated account and profile.', self::account(withProfile: true))
                    + self::failures([
                        401 => 'Missing, unknown or mismatched token.',
                        422 => 'Validation failed; see errors.',
                    ]),
            ]],

            '/v1/user/account' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Change the password',
                'description' => 'Verifies the current password, then rotates the API token, which the '
                    .'previous version did not: a password changed because it might have leaked left '
                    .'every token issued under the old one working. Rotating signs the account out of '
                    .'every other device, so the new token is returned here.',
                'requestBody' => self::formBody([
                    'current_password' => 'Required.',
                    'new_password' => 'Required, at least 6 characters.',
                ], ['current_password', 'new_password']),
                'responses' => self::ok('The new token.', [
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string', 'description' => 'Replaces the token used for this call.'],
                        'message' => ['type' => 'string'],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Wrong current password, or the new one is too short.',
                ]),
            ]],

            '/v1/user/deactivate' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Close the account',
                'description' => 'Blocks the account and rotates the token, so every signed-in device '
                    .'stops working at once. Nothing is deleted: the requests and listings are '
                    .'referenced by other peoples records. An account part-way through a six month '
                    .'subscription cannot be closed this way, which is carried over.',
                'responses' => self::ok('Closed.', [
                    'type' => 'object',
                    'properties' => ['deactivated' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    409 => 'A six month subscription is still running.',
                ]),
            ]],

            '/v1/user/report-issue' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Report a problem to the administrators',
                'description' => 'Sent from the applications own address with the reporter in Reply-To. '
                    .'The previous version set From to the reporters address, which fails SPF and DKIM '
                    .'for any domain they do not own, and put their text into the message unescaped.',
                'requestBody' => self::formBody([
                    'subject' => 'Required.',
                    'issue_type' => 'Required.',
                    'message' => 'Required, up to 5000 characters.',
                ], ['subject', 'issue_type', 'message']),
                'responses' => self::ok('Sent.', [
                    'type' => 'object',
                    'properties' => ['reported' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Validation failed; see errors.',
                ]),
            ]],

            '/v1/user-settings/get-notification-settings' => ['get' => [
                'tags' => ['User'],
                'summary' => 'Which notifications the caller wants',
                'description' => 'Returns the switches in effect. Only 11 accounts of 5,600 have ever '
                    .'set these; the previous version answered everyone else with an error, so this '
                    .'returns the defaults instead and reports whether the user has chosen.',
                'responses' => self::ok('The switches in effect.', self::notificationFlags())
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/user-settings/save-notification-settings' => ['post' => [
                'tags' => ['User'],
                'summary' => 'Change which notifications the caller wants',
                'description' => 'Each switch is independent: only the ones named in the request are '
                    .'changed. The previous version defaulted every absent switch to Yes, so a request '
                    .'that turned one off turned the other three back on.',
                'requestBody' => self::formBody([
                    'all_notifications' => 'Optional boolean.',
                    'wildlife_notifications' => 'Optional boolean.',
                    'awareness_notifications' => 'Optional boolean.',
                    'subscriber_notifications' => 'Optional boolean.',
                ], []),
                'responses' => self::ok('The switches now in effect.', self::notificationFlags())
                    + self::failures([
                        401 => 'Missing, unknown or mismatched token.',
                        422 => 'A switch was not a boolean.',
                    ]),
            ]],

            '/v1/notifications/community-awareness' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Alert everyone near a listing',
                'description' => 'Creates a category 1 notification and sends it to every '
                    .'profile whose recorded position is within the configured radius, 25 km by '
                    .'default, with a push to those who want one. '
                    .'Worth knowing before judging this endpoint: a profile position is whatever '
                    .'coordinates a client last sent, and a client without a location sends the '
                    .'maps default centre, so 5,610 of 5,611 profiles share one point and no real '
                    .'listing has anybody within 25 km of it. A further 332 listings have no '
                    .'coordinates. Both cases reach nobody, and report recipients as 0 rather '
                    .'than failing.',
                'requestBody' => self::formBody(['land_id' => 'Required. The listing the notification is about.', 'message_body' => 'Required, up to 5000 characters.', 'notification_sub_category' => 'Optional. An id from notifications/categories.'], ['land_id', 'message_body']),
                'responses' => [
                    '201' => [
                        'description' => 'Created, with the number of recipients.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => self::broadcastResult()]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing.',
                    422 => 'Validation failed; see errors.',
                ]),
            ]],

            '/v1/notifications/subscriber-notification' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Post to a listings subscribers',
                'description' => 'Creates a category 2 notification for the people who subscribed '
                    .'to that listing. Restricted to the owner or an accepted manager, which is a '
                    .'change: the previous version checked only that the listing existed, so any '
                    .'authenticated caller could broadcast to any listings subscribers, who read '
                    .'these as coming from the property they chose to follow. Refusal is 404, so a '
                    .'caller learns nothing about a listing they do not manage.',
                'requestBody' => self::formBody(['land_id' => 'Required. The listing the notification is about.', 'message_body' => 'Required, up to 5000 characters.', 'notification_sub_category' => 'Optional. An id from notifications/categories.'], ['land_id', 'message_body']),
                'responses' => [
                    '201' => [
                        'description' => 'Created, with the number of recipients.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => self::broadcastResult()]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing, or you do not manage it.',
                    422 => 'Validation failed; see errors.',
                ]),
            ]],

            '/v1/notifications/hunting-alert' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Alert hunters whose tags match',
                'description' => 'Creates a category 3 notification for hunters holding an active '
                    .'tag in one of the given management units for that species and gender. Not a '
                    .'proximity alert. When nothing matches, the answer is 409 and nothing is '
                    .'stored, because an alert nobody can receive is not a draft worth keeping. '
                    .'Forwarding to PEASI, the provincial alerting service, happens here when it '
                    .'is configured; previously it happened only through notifications/submit, so '
                    .'whether an alert was forwarded depended on which endpoint the app called.',
                'requestBody' => self::formBody([
                    'land_id' => 'Required.',
                    'province' => 'Required. Must match the province on the management units.',
                    'wmus' => 'Required. Management unit numbers, as an array or comma separated.',
                    'species_id' => 'Required.',
                    'species_gender' => 'Required.',
                    'hunters_count' => 'Required, at least 1.',
                    'message_body' => 'Required, up to 5000 characters.',
                    'tag_required' => 'Optional. When set, only hunters holding a tag number match.',
                ], [
                    'land_id', 'province', 'wmus', 'species_id', 'species_gender',
                    'hunters_count', 'message_body',
                ]),
                'responses' => [
                    '201' => [
                        'description' => 'Created, with the number of recipients.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => self::broadcastResult()]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing.',
                    409 => 'No hunters match the criteria; nothing was stored.',
                    422 => 'Validation failed, or no management unit matched.',
                ]),
            ]],

            '/v1/notifications/submit' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Post a notification of any category',
                'description' => 'The generic entry point, kept because apps in the field use it. '
                    .'Dispatches on notification_category to the same code as the three endpoints '
                    .'above: 1 community awareness, 2 subscriber, 3 hunting alert. In the previous '
                    .'version this was a fourth implementation that behaved differently from all '
                    .'three — it ignored notification settings entirely, and it was the only path '
                    .'that forwarded to PEASI. Prefer the specific endpoints.',
                'requestBody' => self::formBody([
                    'notification_category' => 'Required. 1, 2 or 3.',
                    'land_id' => 'Required. The listing the notification is about.', 'message_body' => 'Required, up to 5000 characters.', 'notification_sub_category' => 'Optional. An id from notifications/categories.',
                ], ['notification_category', 'land_id', 'message_body']),
                'responses' => [
                    '201' => [
                        'description' => 'Created, with the number of recipients.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => self::broadcastResult()]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such listing, or you do not manage it.',
                    409 => 'A hunting alert that matched no hunters.',
                    422 => 'Validation failed; see errors.',
                ]),
            ]],

            '/v1/notifications/get-notification' => ['get' => [
                'tags' => ['Notifications'],
                'summary' => 'One notification the caller received',
                'description' => 'Readable only by a recipient. A notification that does not '
                    .'exist and one sent to somebody else both answer 404, so the existence of a '
                    .'message is not confirmed to a caller who cannot read it. The fields are an '
                    .'allowlist; the previous version selected every column of the table. '
                    .'wmu_list is an array here, where it was the stored JSON string.',
                'parameters' => [
                    self::param('notification_id', 'integer', 'The notification to read.', true),
                ],
                'responses' => self::ok('The notification, with the callers own read state.', [
                    'type' => 'object',
                    'properties' => ['item' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'notification_category' => ['type' => 'integer'],
                            'type' => ['type' => 'string', 'description' => 'Category name.'],
                            'category' => ['type' => 'string', 'nullable' => true, 'description' => 'Sub-category name.'],
                            'message_body' => ['type' => 'string'],
                            'sender' => ['type' => 'string', 'nullable' => true],
                            'sender_id' => ['type' => 'integer'],
                            'land_id' => ['type' => 'integer', 'nullable' => true],
                            'land_name' => ['type' => 'string', 'nullable' => true],
                            'address' => ['type' => 'string', 'nullable' => true],
                            'coordinates' => ['type' => 'string', 'nullable' => true],
                            'species_name' => ['type' => 'string', 'nullable' => true],
                            'species_gender' => ['type' => 'string', 'nullable' => true],
                            'hunters_count' => ['type' => 'integer', 'nullable' => true],
                            'tag_required' => ['type' => 'boolean'],
                            'wmu_list' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'tag_id' => ['type' => 'integer', 'nullable' => true,
                                'description' => 'For a hunting alert, which of the callers tags matched.'],
                            'is_read' => ['type' => 'boolean'],
                            'is_admin_notification' => ['type' => 'boolean'],
                            'created' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ]],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such notification, or it was not sent to you.',
                    422 => 'notification_id missing.',
                ]),
            ]],

            '/v1/notifications/read' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Mark one as read',
                'description' => 'A POST. The previous version was a GET that wrote, so anything '
                    .'prefetching a link marked notifications read. Read state belongs to the '
                    .'recipient, so this changes only the callers own copy.',
                'requestBody' => self::formBody(
                    ['notification_id' => 'Required.'],
                    ['notification_id'],
                ),
                'responses' => self::ok('Marked.', [
                    'type' => 'object',
                    'properties' => ['read' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such notification, or it was not sent to you.',
                    422 => 'notification_id missing.',
                ]),
            ]],

            '/v1/notifications/delete-notification' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Remove the callers copy',
                'description' => 'Deletes the callers own copy. The notification itself and every '
                    .'other recipients copy are untouched.',
                'requestBody' => self::formBody(
                    ['notification_id' => 'Required.'],
                    ['notification_id'],
                ),
                'responses' => self::ok('Removed.', [
                    'type' => 'object',
                    'properties' => ['deleted' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such notification, or it was not sent to you.',
                    422 => 'notification_id missing.',
                ]),
            ]],

            '/v1/notifications/report-notification' => ['post' => [
                'tags' => ['Notifications'],
                'summary' => 'Report a notification to the administrators',
                'description' => 'Records the report, flags the callers own copy as reported so '
                    .'the app can show it, and mails the admin address. The previous version left '
                    .'the flag unset although the column exists for it, so a user could report the '
                    .'same message repeatedly with no sign it had been done. Mail is sent from the '
                    .'application with the reporter in Reply-To, not as the reporter.',
                'requestBody' => self::formBody([
                    'notification_id' => 'Required.',
                    'reason' => 'Required, up to 5000 characters.',
                ], ['notification_id', 'reason']),
                'responses' => self::ok('Reported.', [
                    'type' => 'object',
                    'properties' => ['reported' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such notification, or it was not sent to you.',
                    422 => 'Validation failed; see errors.',
                ]),
            ]],

            '/v1/hunters-tags/get-all-tags' => ['get' => [
                'tags' => ['Hunters tags'],
                'summary' => 'The callers tags',
                'description' => 'Marks the callers own tags expired when their season has '
                    .'passed, then lists them. The old endpoint swept the whole table rather '
                    .'than one account, so one hunter opening this screen rewrote every other '
                    .'hunters tags; alert matching no longer depends on the sweep having run.',
                'responses' => self::ok('The tags, newest first.',
                    self::itemsOf('#/components/schemas/Tag'))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/hunters-tags/get-tag' => ['get' => [
                'tags' => ['Hunters tags'],
                'summary' => 'One tag, with its management units',
                'description' => 'Readable only by the hunter who filed it. A tag belonging to '
                    .'somebody else and one that does not exist both answer 404.',
                'parameters' => [self::param('tag_id', 'integer', 'The tag to read.', true)],
                'responses' => self::ok('The tag.', [
                    'type' => 'object',
                    'properties' => ['item' => ['allOf' => [
                        ['$ref' => '#/components/schemas/Tag'],
                        ['type' => 'object', 'properties' => ['wmus' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'The unit numbers this tag covers.',
                        ]]],
                    ]]],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such tag, or it is not yours.',
                    422 => 'tag_id missing.',
                ]),
            ]],

            '/v1/hunters-tags/add-tag' => ['post' => [
                'tags' => ['Hunters tags'],
                'summary' => 'File a tag',
                'description' => 'Creates a tag for the caller and records the units it covers. '
                    .'Two changes from the old endpoint, both about the units: they are resolved '
                    .'by province and number together, where the old one resolved by number '
                    .'alone and so could file a Saskatchewan tag against Manitobas unit of the '
                    .'same number; and a number that does not exist is now a validation failure. '
                    .'The old endpoint inserted the unknown number into the reference table, '
                    .'which is how 58 units came to have no province at all. The tag and its '
                    .'units are written in one transaction.',
                'requestBody' => self::formBody(['tag_required' => 'Optional, default true. When false the physical tag details are not required.', 'tag_number' => 'Required unless tag_required is false. Integer.', 'wildlife_id_number' => 'Required unless tag_required is false. Integer.', 'season_start_date' => 'Required unless tag_required is false. YYYY-MM-DD.', 'season_end_date' => 'Required unless tag_required is false. YYYY-MM-DD, not before the start.', 'species_id' => 'Required. Must exist; see wmu/species.', 'species_gender' => 'Required.', 'province' => 'Required. Must be the province the units are in.', 'wmus' => 'Required. Unit numbers, as an array or comma separated. Every one must exist in that province.', 'status' => 'Required. Active, Expired or Used.'], ['species_id', 'species_gender', 'province', 'wmus', 'status']),
                'responses' => [
                    '201' => [
                        'description' => 'Created.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => ['id' => ['type' => 'integer']],
                                ]]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Validation failed, or a unit does not exist in that province.',
                ]),
            ]],

            '/v1/hunters-tags/update-tag' => ['post' => [
                'tags' => ['Hunters tags'],
                'summary' => 'Replace a tag',
                'description' => 'Replaces one of the callers tags and the units it covers. The '
                    .'units are rebuilt inside a transaction; the old version deleted and '
                    .'reinserted them with no transaction, so a failure part way through left a '
                    .'tag covering some of the units asked for and none of the rest.',
                'requestBody' => self::formBody(
                    ['tag_id' => 'Required. One of your own tags.', 'tag_required' => 'Optional, default true. When false the physical tag details are not required.', 'tag_number' => 'Required unless tag_required is false. Integer.', 'wildlife_id_number' => 'Required unless tag_required is false. Integer.', 'season_start_date' => 'Required unless tag_required is false. YYYY-MM-DD.', 'season_end_date' => 'Required unless tag_required is false. YYYY-MM-DD, not before the start.', 'species_id' => 'Required. Must exist; see wmu/species.', 'species_gender' => 'Required.', 'province' => 'Required. Must be the province the units are in.', 'wmus' => 'Required. Unit numbers, as an array or comma separated. Every one must exist in that province.', 'status' => 'Required. Active, Expired or Used.'],
                    array_merge(['tag_id'], ['species_id', 'species_gender', 'province', 'wmus', 'status']),
                ),
                'responses' => self::ok('Updated.', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such tag, or it is not yours.',
                    422 => 'Validation failed, or a unit does not exist in that province.',
                ]),
            ]],

            '/v1/hunters-tags/delete-tag' => ['post' => [
                'tags' => ['Hunters tags'],
                'summary' => 'Remove a tag',
                'description' => 'Deletes one of the callers tags and the units it covered, in '
                    .'one transaction.',
                'requestBody' => self::formBody(
                    ['tag_id' => 'Required. One of your own tags.'],
                    ['tag_id'],
                ),
                'responses' => self::ok('Removed.', [
                    'type' => 'object',
                    'properties' => ['deleted' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such tag, or it is not yours.',
                    422 => 'tag_id missing.',
                ]),
            ]],

            '/v1/chat/list' => ['get' => [
                'tags' => ['Chat'],
                'summary' => 'The callers conversations',
                'description' => 'Titles and timestamps, most recently updated first. The '
                    .'history itself is not included; read one with chat/get-history. The old '
                    .'endpoint accepted start and limit and ignored both, so they are not here.',
                'responses' => self::ok('The conversations.', [
                    'type' => 'object',
                    'properties' => ['items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'chat_title' => ['type' => 'string'],
                                'created' => ['type' => 'string', 'format' => 'date-time'],
                                'updated' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            ],
                        ],
                    ]],
                ]) + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/chat/get-history' => ['get' => [
                'tags' => ['Chat'],
                'summary' => 'One conversation',
                'description' => 'Readable only by the account that saved it. The history comes '
                    .'back decoded, which means it mirrors what was posted: history sent as a '
                    .'JSON string reads back as a string, history sent as a form array reads '
                    .'back as an object. That is how the old API stored it and existing rows '
                    .'depend on it.',
                'parameters' => [self::param('chat_id', 'integer', 'The conversation to read.', true)],
                'responses' => self::ok('The conversation.', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'chat_title' => ['type' => 'string'],
                        'history' => ['description' => 'Whatever was stored; see above.'],
                        'created' => ['type' => 'string', 'format' => 'date-time'],
                        'updated' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such conversation, or it is not yours.',
                    422 => 'chat_id missing.',
                ]),
            ]],

            '/v1/chat/add-history' => ['post' => [
                'tags' => ['Chat'],
                'summary' => 'Save a new conversation',
                'description' => 'The question becomes the title. History is capped at a '
                    .'megabyte, which the old endpoint did not limit at all.',
                'requestBody' => self::formBody([
                    'question' => 'Required, up to 1000 characters. Becomes the title.',
                    'history' => 'Required. Stored as sent; see chat/get-history.',
                ], ['question', 'history']),
                'responses' => [
                    '201' => [
                        'description' => 'Saved.',
                        'content' => ['application/json' => ['schema' => [
                            'allOf' => [
                                ['$ref' => '#/components/schemas/Envelope'],
                                ['type' => 'object', 'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => ['id' => ['type' => 'integer']],
                                ]]],
                            ],
                        ]]],
                    ],
                ] + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Validation failed, or the history is too large.',
                ]),
            ]],

            '/v1/chat/update-history' => ['post' => [
                'tags' => ['Chat'],
                'summary' => 'Replace a conversations history',
                'description' => 'Replaces the history of one of the callers conversations and '
                    .'sets its updated time, which chat/list orders by.',
                'requestBody' => self::formBody([
                    'chat_id' => 'Required. One of your own conversations.',
                    'history' => 'Required. Stored as sent; see chat/get-history.',
                ], ['chat_id', 'history']),
                'responses' => self::ok('Updated.', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such conversation, or it is not yours.',
                    422 => 'Validation failed, or the history is too large.',
                ]),
            ]],

            '/v1/chat/delete-history' => ['post' => [
                'tags' => ['Chat'],
                'summary' => 'Delete a conversation',
                'requestBody' => self::formBody(
                    ['chat_id' => 'Required. One of your own conversations.'],
                    ['chat_id'],
                ),
                'responses' => self::ok('Deleted.', [
                    'type' => 'object',
                    'properties' => ['deleted' => ['type' => 'boolean']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such conversation, or it is not yours.',
                    422 => 'chat_id missing.',
                ]),
            ]],

            '/v1/wmu/games' => ['get' => [
                'tags' => ['Reference'],
                'summary' => 'One management unit and the games in it',
                'description' => 'The id parameter is the unit number, not wmu_id: the old '
                    .'endpoint named it that way and the app sends it, so the name is kept. Unit '
                    .'numbers repeat across provinces — 3 exists in six of them — which is why '
                    .'the province is required. The games list is distinct here; the old one '
                    .'appended a games row per game detail, so a unit with two details for the '
                    .'same game listed it twice.',
                'parameters' => [
                    self::param('id', 'string', 'The unit number, for example 3 or 69A-2.', true),
                    self::param('province', 'string', 'The province that unit is in.', true),
                ],
                'responses' => self::ok('The unit, its game details and its games.', [
                    'type' => 'object',
                    'properties' => [
                        'wmu' => ['$ref' => '#/components/schemas/ManagementUnit'],
                        'game_details' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'game_detail_id' => ['type' => 'integer'],
                                    'wmu_id' => ['type' => 'integer'],
                                    'game_id' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                        'games' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'game_id' => ['type' => 'integer'],
                                    'game_name' => ['type' => 'string', 'nullable' => true],
                                    'province' => ['type' => 'string', 'nullable' => true],
                                    'regulations_pdf' => ['type' => 'string', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No such unit in that province.',
                    422 => 'id or province missing.',
                ]),
            ]],

            '/v1/wmu/game-season-details' => ['get' => [
                'tags' => ['Reference'],
                'summary' => 'The seasons for one game detail',
                'description' => 'Seasons live in a table per province, so the province decides '
                    .'which table is read and only the nine provinces that have one are '
                    .'accepted: ab, bc, mb, nb, ns, on, pe, sk, yk. NL, NT, NU and QC have no '
                    .'season table. wmu_id is required because the app sends it and is not used '
                    .'as a filter, exactly as before — a game detail already belongs to one '
                    .'unit, and whether the app sends a wmu_id or a unit number here cannot be '
                    .'settled from the data, so filtering on a guess would silently return '
                    .'nothing.',
                'parameters' => [
                    self::param('wmu_id', 'string', 'Required, and not used as a filter.', true),
                    self::param('game_detail_id', 'integer', 'The game detail to read.', true),
                    self::param('province', 'string', 'One of the nine with a season table.', true),
                ],
                'responses' => self::ok('The seasons.', [
                    'type' => 'object',
                    'properties' => ['items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'species_name' => ['type' => 'string', 'nullable' => true,
                                    'description' => 'Whitespace collapsed; 13 of the stored '
                                        .'names hold a carriage return and newline.'],
                                'season_id' => ['type' => 'integer'],
                                'game_detail_id' => ['type' => 'integer'],
                                'species_id' => ['type' => 'integer'],
                                'type' => ['type' => 'string', 'nullable' => true],
                                'season' => ['type' => 'string', 'nullable' => true],
                                'bag_limit' => ['type' => 'string', 'nullable' => true],
                                'daily_limit' => ['type' => 'string', 'nullable' => true],
                                'posession_limit' => ['type' => 'string', 'nullable' => true,
                                    'description' => 'Spelled as the column is.'],
                                'day_restrictions' => ['type' => 'string', 'nullable' => true],
                                'notes' => ['type' => 'string', 'nullable' => true],
                                'alerts' => ['type' => 'string', 'nullable' => true],
                                'season_start_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                'season_end_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                            ],
                        ],
                    ]],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    422 => 'Validation failed, or that province has no season table.',
                ]),
            ]],

            '/v1/wmu/species' => ['get' => [
                'tags' => ['Reference'],
                'summary' => 'Every species',
                'description' => 'For the pickers, and for the species_id a tag or a hunting '
                    .'alert carries. Names have their whitespace collapsed.',
                'responses' => self::ok('The species.', [
                    'type' => 'object',
                    'properties' => ['items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'species_id' => ['type' => 'integer'],
                                'species_name' => ['type' => 'string', 'nullable' => true],
                            ],
                        ],
                    ]],
                ]) + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/fishing-lands/province-regulations' => ['get' => [
                'tags' => ['Reference'],
                'summary' => 'Fishing regulations for every province',
                'description' => 'One document per province, thirteen rows. The region column '
                    .'exists for a province needing more than one and is null in every row.',
                'responses' => self::ok('The regulations.',
                    self::itemsOf('#/components/schemas/FishingRegulations'))
                    + self::failures([401 => 'Missing, unknown or mismatched token.']),
            ]],

            '/v1/fishing-lands/regulations' => ['get' => [
                'tags' => ['Reference'],
                'summary' => 'Fishing regulations for one province',
                'description' => 'The old endpoint defaulted the province to AB while also '
                    .'marking it required, so the default was unreachable; it is simply required '
                    .'here. Note that this table spells Yukon YT where the season tables use yk.',
                'parameters' => [self::param('province', 'string', 'For example AB.', true)],
                'responses' => self::ok('The regulations.', [
                    'type' => 'object',
                    'properties' => ['item' => ['$ref' => '#/components/schemas/FishingRegulations']],
                ]) + self::failures([
                    401 => 'Missing, unknown or mismatched token.',
                    404 => 'No regulations for that province.',
                    422 => 'province missing.',
                ]),
            ]],
        ];
    }
}
