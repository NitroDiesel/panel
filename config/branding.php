<?php

$themeCss = env('APP_BRAND_THEME_CSS');

return [
    /*
    |--------------------------------------------------------------------------
    | Theme Stylesheet
    |--------------------------------------------------------------------------
    |
    | An optional stylesheet loaded after the panel's own styles on the client
    | and admin pages. It can restyle the panel by setting the CSS variables
    | declared in resources/scripts/assets/tailwind.css (the client panel adds
    | its own styles at runtime, after this file, so other rules need selectors
    | more specific than the panel's to take effect). Only a path on this
    | site ("/branding/theme.css") or an https:// URL is accepted; an external
    | URL must also be allowed by APP_CSP_EXTRA_STYLE_SRC when CSP is enforced.
    |
    */

    'theme_css' => is_string($themeCss) && preg_match('#^(/(?!/)|https://)\S+$#', $themeCss) === 1 ? $themeCss : null,
];
