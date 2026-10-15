<?php

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;

class CspReportController extends Controller
{
    /**
     * Receives Content-Security-Policy violation reports from browsers and logs the parts
     * that identify what was blocked and where. Like /up, this route is outside of the
     * "web" group, so reports don't create sessions or need a CSRF token.
     */
    public function __invoke(Request $request): Response
    {
        $report = json_decode($request->getContent(), true);
        $report = is_array($report) ? ($report['csp-report'] ?? $report) : [];

        $fields = [];
        foreach (['document-uri', 'violated-directive', 'effective-directive', 'blocked-uri', 'source-file', 'line-number'] as $field) {
            if (isset($report[$field]) && is_scalar($report[$field])) {
                $fields[$field] = Str::limit((string) $report[$field], 255);
            }
        }

        if (!empty($fields)) {
            Log::debug('Content-Security-Policy violation.', $fields);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
