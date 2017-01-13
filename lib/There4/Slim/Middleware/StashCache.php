<?php
namespace There4\Slim\Middleware;

use \Stash\Pool;

class StashCache
{
    public function __construct($container)
    {
        $this->container = $container;
        $this->stash = $container->get('stash');
    }

    public function __invoke($req, $resp, $next)
    {
        $stash = $this->stash;

        // Only cache GET requests
        if (!$req->isGet()) {
            $next($req, $resp);
            return;
        }

        // Allow a callback, call_user_func allows an array to be passed
        if (isset($resp->signature)) {
            $signature = is_callable($resp->signature)
                ? call_user_func($resp->signature)
                : $resp->signature;
        } else {
            $signature = $req->getUri()->getPath();
        }

        // Get via the signature if it's not a miss send it to the client
        // and return to halt the response chain
        $stashItem = $stash->getItem('routes' . $signature);
        if (!$stashItem->isMiss()) {
            $data = $stashItem->get(\Stash\Item::SP_PRECOMPUTE, 300);
            $this->container->cache->withLastModified($resp, $data['last_modified']);
            $resp['Content-Type'] = $data['content_type'];
            $resp->getBody()->write($data['body']);
            return $resp;
        }

        // Else we continue on with the middleware change and run the next
        // middleware layer
        $next($req, $resp);

        // If we allow cache and the endpoint ran correctly, cache the result
        if (!empty($resp->allowCache) && ($resp->getStatusCode() == 200)) {
            $this->container->cache->withExpires($resp, time() + 3600);
            $stashItem->set(array(
                'content_type'  => $resp['Content-Type'],
                'body'          => $resp->getBody()->getContents(),
                'last_modified' => time()
            ), $resp->cacheExpiration);
        }

        return $resp;
    }
}
