<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supabase Project URL
    |--------------------------------------------------------------------------
    | Your Supabase project URL, e.g. https://xyzxyzxyz.supabase.co
    | Found in: Supabase Dashboard → Settings → API → Project URL
    */
    'url' => env('SUPABASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Supabase Anon Key
    |--------------------------------------------------------------------------
    | The public anon key for your Supabase project.
    | Found in: Supabase Dashboard → Settings → API → Project API keys → anon
    | Used as the `apikey` header when calling Supabase Auth REST endpoints.
    */
    'anon_key' => env('SUPABASE_ANON_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Mobile Deep Link Scheme
    |--------------------------------------------------------------------------
    | The URL scheme the mobile app has registered for OAuth callbacks.
    | After a successful OAuth sign-in the API redirects to:
    |   {scheme}://auth/callback?token={sanctum_token}
    | On failure:
    |   {scheme}://auth/callback?error={message}
    |
    | The mobile team registers this scheme in the app manifest (one line
    | on both iOS and Android). Change this only if the scheme changes in
    | the app.
    */
    'mobile_redirect_scheme' => env('OAUTH_MOBILE_REDIRECT_SCHEME', 'vidioking'),

    /*
    |--------------------------------------------------------------------------
    | Web Frontend Redirect URL
    |--------------------------------------------------------------------------
    | Full URL to the web frontend's OAuth callback page.
    | e.g. https://app.vidioking.com/auth/callback
    | Reserved for future use if a web client replaces the mobile deep-link
    | flow with a browser redirect.
    */
    'web_redirect_url' => env('OAUTH_WEB_REDIRECT_URL'),

];
