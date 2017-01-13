<?php
namespace There4\Slim\Middleware;

use \Stash\Pool;

class StashCache
{
    public function __construct($container, $expiration = 3600)
    {
        $this->container = $container;
        $this->stash = $container->get('stash');
        $this->expiration = $expiration;
    }

    public function __invoke($req, $resp, $next)
    {
        $stash = $this->stash;

        // Only cache GET requests
        if (!$req->isGet()) {
            $next($req, $resp);
            return $resp;
        }

        // Allow a callback, call_user_func allows an array to be passed
        if ($req->getAttribute('signature')) {
            $signature = is_callable($req->getAttribute('signature'))
                ? call_user_func($resp->getAttribute('signature'))
                : $resp->getAttribute('signature');
        } else {
            $signature = $req->getUri()->getPath();
        }

        // Get via the signature if it's not a miss send it to the client
        // and return to halt the response chain
        $stashItem = $stash->getItem('routes' . $signature);
        if (!$stashItem->isMiss()) {
            $data = $stashItem->get(\Stash\Item::SP_PRECOMPUTE, 300);
            $resp = $this->container->cache->withLastModified($resp, $data['last_modified']);
            $resp = $resp->withHeader('Content-Type', $data['content_type']);
            $resp->getBody()->write($data['body']);
            return $resp;
        }

        // Else we continue on with the middleware change and run the next
        // middleware layer
        $next($req, $resp);

        // If we allow cache and the endpoint ran correctly, cache the result
        if ($resp->getStatusCode() == 200) {
            $resp = $this->container->cache->withExpires($resp, time() + 3600);
            $stashItem->set(array(
                'content_type'  => $resp->getHeader('Content-Type'),
                'body'          => $resp->getBody()->getContents(),
                'last_modified' => time()
            ), $this->expiration);
        }

        return $resp;
    }
}
