<?php

namespace Pterodactyl\Extensions\Laracasts;

use Pterodactyl\Http\ContentSecurityPolicy;
use Laracasts\Utilities\JavaScript\LaravelViewBinder;

/**
 * Outputs the variables passed to JavaScript::put() in a script tag that carries the
 * request's Content-Security-Policy nonce; the package's own binder writes a bare
 * <script> tag, which an enforced policy blocks.
 */
class NonceViewBinder extends LaravelViewBinder
{
    public function bind($js)
    {
        foreach ((array) $this->views as $view) {
            $this->event->listen("composing: {$view}", function () use ($js) {
                $nonce = e(app(ContentSecurityPolicy::class)->nonce());

                echo "<script nonce=\"{$nonce}\">{$js}</script>";
            });
        }
    }
}
