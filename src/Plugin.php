<?php
/**
 * This file is part of PhergieUrl.
 *
 ** (c) 2014 Cees-Jan Kiewiet
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phergie\Irc\Plugin\React\Url;

use GuzzleHttp\Message\Response;
use Phergie\Irc\Plugin\React\Url\Filter\UrlEvent;
use React\EventLoop\LoopInterface;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueue;
use Phergie\Irc\Client\React\LoopAwareInterface;
use Phergie\Irc\Event\UserEvent;
use Phergie\Irc\Plugin\React\EventFilter\FilterInterface;
use React\Promise\Deferred;
use Phergie\Plugin\Http\Request;

/**
 * Plugin for Display URL information about links.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\Url
 */
class Plugin extends AbstractPlugin implements LoopAwareInterface
{
    /**
     * @var UrlHandlerInterface
     */
    protected $handler = null;
    /**
     * @var UrlHandlerInterface
     */
    protected $shortenTimeout = 15;
    /**
     * @var bool
     */
    protected $hostUrlEmitsOnly = false;

    /**
     * @var FilterInterface
     */
    protected $filter = null;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Accepts plugin configuration.
     *
     * Supported keys:
     *
     * handler - handler to create a message for the given URL
     * shortenTimeout - timeout in seconds how long it can take to short an URL
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (
            isset($config['handler']) &&
            in_array(UrlHandlerInterface::class, class_implements($config['handler']))
        ) {
            $this->handler = $config['handler'];
        } else {
            $this->handler = new DefaultUrlHandler();
        }
        if (isset($config['shortenTimeout'])) {
            $this->shortenTimeout = $config['shortenTimeout'];
        }
        if (isset($config['hostUrlEmitsOnly'])) {
            $this->hostUrlEmitsOnly = boolval($config['hostUrlEmitsOnly']);
        }
        if (
            isset($config['filter']) &&
            in_array(FilterInterface::class, class_implements($config['filter']))
        ) {
            $this->filter = $config['filter'];
        }
    }

    /**
     * @param LoopInterface $loop
     */
    public function setLoop(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     *
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            'irc.received.privmsg' => 'handleIrcReceived',
        ];
    }

    /**
     * @return UrlHandlerInterface
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * @param string $message
     */
    public function logDebug($message)
    {
        $this->logger->debug('[Url]' . $message);
    }

    public function handleIrcReceived(UserEvent $event, EventQueue $queue)
    {
        $params = $event->getParams();
        $extractor = new \Twitter_Extractor($params['text']);
        $urls = $extractor->extractURLs();

        foreach ($urls as $url) {
            if (
                $this->filter !== null &&
                $this->filter->filter(new UrlEvent($url, $event)) !== false
            ) {
                continue;
            }
            $this->handleUrl($url, $event, $queue);
        }
    }

    /**
     * @param string $url
     * @param UserEvent $event
     * @param EventQueue $queue
     *
     * @return bool
     */
    public function handleUrl($url, UserEvent $event, EventQueue $queue)
    {
        $parsedUrl = parse_url($url);

        if (
            (!isset($parsedUrl['host']) && !isset($parsedUrl['path'])) ||
            (!isset($parsedUrl['host']) && isset($parsedUrl['path']) && $parsedUrl['path'] == '')
           ) {
            return false;
        }

        $requestId = uniqid();
        $this->logDebug('[' . $requestId . ']Found url: ' . $url);

        if (count($parsedUrl) == 1 && isset($parsedUrl['path'])) {
            $url = 'http://' . $parsedUrl['path'] . '/';
            $this->logDebug('[' . $requestId . ']Corrected url: ' . $url);
        }

        if ($this->emitUrlEvents($requestId, $url, $event, $queue) && !$this->hostUrlEmitsOnly) {
            $this->logDebug('[' . $requestId . ']Emitting: http.request');
            $this->emitter->emit('http.request', [$this->createRequest($requestId, $url, $event, $queue)]);
        }

        $this->logDebug('[' . $requestId . ']Emitting: url.host.all');
        $this->emitter->emit('url.host.all', [$url, $event, $queue]);

        return true;
    }

    /**
     * @param string $requestId
     * @param string $url
     * @param UserEvent $event
     * @param EventQueue $queue
     *
     * @return Request
     */
    public function createRequest($requestId, $url, UserEvent $event, EventQueue $queue)
    {
        $start = microtime(true);
        return new Request([
            'url' => $url,
            'responseCallback' => function ($headers, $code) use ($requestId, $start) {
                $end = microtime(true);
                $this->logDebug('[' . $requestId . ']Reponse (after ' . ($end - $start) . 's): ' . $code);
            },
            'resolveCallback' =>
                function (Response $response) use ($requestId, $url, $event, $queue, $start) {
                    $data = $response->getBody()->getContents();
                    $headers = $response->getHeaders();
                    array_walk($headers, function (&$value, $key) use ($response) {
                        $value = $response->getHeader($key);
                    });
                    $code = $response->getStatusCode();
                    $end = microtime(true);
                    $message = '[';
                    $message .= $requestId;
                    $message .= ']Download complete (after ';
                    $message .= ($end - $start);
                    $message .= 's): ';
                    $message .= strlen($data);
                    $message .= ' in length length';
                    $this->logDebug($message);
                    $this->emitShorteningEvents($requestId, $url)->then(
                        function ($shortUrl) use ($url, $data, $headers, $code, $end, $start, $event, $queue) {
                            $this->sendMessage(
                                new Url($url, $data, $headers, $code, $end - $start, $shortUrl),
                                $event,
                                $queue
                            );
                        },
                        function () use ($url, $data, $headers, $code, $end, $start, $event, $queue) {
                            $this->sendMessage(new Url($url, $data, $headers, $code, $end - $start), $event, $queue);
                        }
                    );
                },
        ]);
    }

    /**
     * @param string $requestId
     * @param string $url
     * @param UserEvent $event
     * @param EventQueue $queue
     *
     * @return bool
     */
    protected function emitUrlEvents($requestId, $url, UserEvent $event, EventQueue $queue)
    {
        $host = Url::extractHost($url);

        $eventName = 'url.host.' . $host;
        if (count($this->emitter->listeners($eventName)) > 0) {
            $this->logDebug('[' . $requestId . ']Emitting: ' . $eventName);
            $this->emitter->emit($eventName, [$url, $event, $queue]);
            return false;
        } else {
            return true;
        }
    }

    /**
     * @param string $requestId
     * @param string $url
     *
     * @return \React\Promise\DeferredPromise
     */
    protected function emitShorteningEvents($requestId, $url)
    {
        $host = Url::extractHost($url);
        list($privateDeferred, $userFacingPromise) = $this->preparePromises();

        $eventName = 'url.shorten.';
        if (count($this->emitter->listeners($eventName . $host)) > 0) {
            $eventName .= $host;
            $this->logDebug('[' . $requestId . ']Emitting: ' . $eventName);
            $this->emitter->emit($eventName, [$url, $privateDeferred]);
        } elseif (count($this->emitter->listeners($eventName . 'all')) > 0) {
            $eventName .= 'all';
            $this->logDebug('[' . $requestId . ']Emitting: ' . $eventName);
            $this->emitter->emit($eventName, [$url, $privateDeferred]);
        } else {
            $this->loop->addTimer(0.1, function () use ($privateDeferred) {
                $privateDeferred->reject();
            });
        }

        return $userFacingPromise;
    }

    /**
     * @return array
     */
    protected function preparePromises()
    {
        $userFacingDeferred = new Deferred();
        $privateDeferred = new Deferred();
        $userFacingPromise = $userFacingDeferred->promise();
        $privateDeferred->promise()->then(function ($shortUrl) use ($userFacingDeferred) {
            $userFacingDeferred->resolve($shortUrl);
        }, function () use ($userFacingDeferred) {
            $userFacingDeferred->reject();
        });
        $this->loop->addTimer($this->shortenTimeout, function () use ($privateDeferred) {
            $privateDeferred->reject();
        });

        return [
            $privateDeferred,
            $userFacingPromise,
        ];
    }

    /**
     * @param Url $url
     * @param UserEvent $event
     * @param EventQueue $queue
     */
    protected function sendMessage(Url $url, UserEvent $event, EventQueue $queue)
    {
        $message = $this->getHandler()->handle($url);
        $queue->ircPrivmsg($event->getSource(), $message);
    }
}
