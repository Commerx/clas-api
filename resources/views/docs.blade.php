<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLAS API</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; }
    </style>
</head>
<body>
    {{--
        Scalar renders the OpenAPI document and gives every endpoint a
        "Test Request" panel. Because this page is served by the API itself,
        those requests are same-origin and need no CORS configuration — a
        docs page hosted anywhere else could show the spec but not call it.

        This build initialises from the script tag's data attributes; it has
        no global to call. No token is pre-filled on purpose: call
        security/login from this page, copy the `token` out of the response
        into the Authentication panel, and every other endpoint is testable.
    --}}
    <script
        id="api-reference"
        {{-- The version query busts the browser cache, so an updated spec is
             never served stale to someone reviewing it. --}}
        data-url="{{ route('docs.spec') }}?v={{ $specVersion }}"
        data-configuration='@json(['theme' => 'default', 'hideDownloadButton' => false])'
    ></script>

    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.76.2/dist/browser/standalone.min.js"></script>
</body>
</html>
