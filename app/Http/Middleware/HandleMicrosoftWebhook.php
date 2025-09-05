<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleMicrosoftWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Handle validation request
        if ($request->has('validationToken')) {
            return response($request->query('validationToken'))
                ->header('Content-Type', 'text/plain');
        }

        // Ensure request is JSON for notifications
        if ($request->isMethod('post') && ! $request->isJson()) {
            $content = $request->getContent();
            if (! empty($content)) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->initialize(
                        $request->query->all(),
                        $request->request->all(),
                        $request->attributes->all(),
                        $request->cookies->all(),
                        $request->files->all(),
                        $request->server->all(),
                        $content
                    );
                    $request->headers->set('Content-Type', 'application/json');
                }
            }
        }

        return $next($request);
    }
}
