<?php
namespace coderstephen\blog;

use Evenement\EventEmitterTrait;
use FastRoute;
use Icicle\Http\Message\RequestInterface;
use Icicle\Http\Message\Response;
use Icicle\Http\Server\Server;
use Icicle\Loop;
use Icicle\Socket\SocketInterface;
use Icicle\Stream\MemorySink;

class Application
{
    use EventEmitterTrait;

    private $path;
    private $dispatcher;
    private $server;
    private $renderer;
    private $assetManager;
    private $articleStore;

    public function __construct(string $path)
    {
        $this->path = $path;

        $this->dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
            $r->addRoute('GET', '/', actions\IndexAction::class);
            $r->addRoute('GET', '/blog', actions\BlogAction::class);
            $r->addRoute('GET', '/category/{category}', actions\CategoryAction::class);
            $r->addRoute('GET', '/portfolio', actions\PortfolioAction::class);
            $r->addRoute('GET', '/{year:\\d{4}}/{month:\\d{2}}/{day:\\d{2}}/{name}', actions\ArticleAction::class);
            $r->addRoute('GET', '/{asset:(?:content|assets)/[^?]+}[?{query}]', actions\AssetAction::class);
        });

        $this->server = new Server(function ($request, $socket) {
            try {
                yield $this->handleRequest($request, $socket);
            } catch (\Throwable $e) {
                echo $e;
                throw $e;
            }
        });

        $this->renderer = new Renderer($path . '/templates');
        $this->assetManager = new AssetManager($path . '/static');
        $this->articleStore = new ArticleStore($path . '/articles');
    }

    public function getRenderer(): Renderer
    {
        return $this->renderer;
    }

    public function getAssetManager(): AssetManager
    {
        return $this->assetManager;
    }

    public function getArticleStore(): ArticleStore
    {
        return $this->articleStore;
    }

    /**
     * Runs the application.
     *
     * Note that this method takes control of running the event loop. This is necessary
     * so that the server can recover from runtime errors and restart itself.
     *
     * Also, yeah, I'm using goto for, uh, *coughs* performance reasons. Shut up.
     */
    public function run(int $port)
    {
        $this->server->listen($port);

        loop:
        try {
            Loop\run();
        } catch (\Throwable $e) {
            echo "Server crashed!\n" . $e . "\nResarting...\n";
            goto loop;
        }
    }

    private function handleRequest(RequestInterface $request, SocketInterface $socket): \Generator {
        printf(
            "Hello to %s:%d from %s:%d!\n",
            $socket->getRemoteAddress(),
            $socket->getRemotePort(),
            $socket->getLocalAddress(),
            $socket->getLocalPort()
        );

        $dispatched = $this->dispatcher->dispatch(
            $request->getMethod(),
            $request->getRequestTarget()
        );

        switch ($dispatched[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
                $randomStr = '';
                for ($i = 0; $i < 1000; ++$i) {
                    $char = chr(mt_rand(32, 126));
                    if ($char !== '<') {
                        $randomStr .= $char;
                    }
                }

                $html = $this->renderer->render('404', [
                    'randomStr' => $randomStr,
                ]);

                $sink = new MemorySink();
                yield $sink->end($html);

                $response = new Response(404, [
                    'Content-Type' => 'text/html',
                    'Content-Length' => $sink->getLength(),
                ], $sink);
                break;
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $sink = new MemorySink();
                yield $sink->end('405 Method Not Allowed');
                $response = new Response(405, [
                    'Content-Type' => 'text/plain',
                    'Content-Length' => $sink->getLength(),
                ], $sink);
                break;
            case FastRoute\Dispatcher::FOUND:
                $action = new $dispatched[1]($this);
                $response = yield $action->handle($request, $dispatched[2]);
                break;
        }

        yield $response;
    }
}