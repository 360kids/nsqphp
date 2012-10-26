<?php

namespace nsqphp;

use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as ELFactory;

use nsqphp\Logger\LoggerInterface;
use nsqphp\Connection\Lookup;
use nsqphp\Dedupe\DedupeInterface;
use nsqphp\RequeueStrategy\RequeueStrategyInterface;
use nsqphp\Message\MessageInterface;
use nsqphp\Message\Message;

class nsqphpio
{
    /**
     * nsqlookupd service
     * 
     * @var Lookup
     */
    private $nsLookup;
    
    /**
     * Dedupe service
     * 
     * @var DedupeInterface|NULL
     */
    private $dedupe;
    
    /**
     * Requeue strategy
     * 
     * @var RequeueStrategyInterface|NULL
     */
    private $requeueStrategy;
    
    /**
     * Logger, if any enabled
     * 
     * @var LoggerInterface|NULL
     */
    private $logger;
    
    /**
     * Connection timeout - in seconds
     * 
     * @var float
     */
    private $connectionTimeout;
    
    /**
     * Read/write timeout - in seconds
     * 
     * @var float
     */
    private $readWriteTimeout;
    
    /**
     * Read wait timeout - in seconds
     * 
     * @var float
     */
    private $readWaitTimeout;

    /**
     * Connection pool
     * 
     * @var Connection\ConnectionPool
     */
    private $connectionPool;
        
    /**
     * Event loop
     * 
     * @var LoopInterface
     */
    private $loop;
    
    /**
     * Wire reader
     * 
     * @var Wire\Reader
     */
    private $reader;
    
    /**
     * Wire writer
     * 
     * @var Wire\Writer
     */
    private $writer;
    
    /**
     * Long ID (of who we are)
     * 
     * @var string
     */
    private $longId;
    
    /**
     * Short ID (of who we are)
     * 
     * @var string
     */
    private $shortId;
    
    /**
     * Constructor
     * 
     * @param Lookup $nsLookup Lookup service for hosts from topic
     * @param DedupeInterface|NULL $dedupe Deduplication service (optional)
     * @param RequeueStrategyInterface|NULL $requeueStrategy Our strategy
     *      for dealing with failures whilst processing SUBbed messages via
     *      callback - if any (optional)
       @param LoggerInterface|NULL $logger Logging service (optional)
     */
    public function __construct(
            Lookup $nsLookup,
            DedupeInterface $dedupe = NULL,
            RequeueStrategyInterface $requeueStrategy = NULL,
            LoggerInterface $logger = NULL,
            $connectionTimeout = 3,
            $readWriteTimeout = 3,
            $readWaitTimeout = 15
            )
    {
        $this->nsLookup = $nsLookup;
        $this->dedupe = $dedupe;
        $this->requeueStrategy = $requeueStrategy;
        $this->logger = $logger;
        
        $this->connectionTimeout = $connectionTimeout;
        $this->readWriteTimeout = $readWriteTimeout;
        $this->readWaitTimeout = $readWaitTimeout;
        
        $this->connectionPool = new Connection\ConnectionPool;
        $this->loop = ELFactory::create();
        
        $this->reader = new Wire\Reader;
        $this->writer = new Wire\Writer;

        $hn = exec('hostname -f');
        $parts = explode('.', $hn);
        $this->shortId = $parts[0];
        $this->longId = $hn;
    }
    
    /**
     * Destructor
     */
    public function __destruct()
    {
        // say goodbye to each connection
        foreach ($this->connectionPool as $connection) {
            $connection->write($this->writer->close());
            if ($this->logger) {
                $this->logger->info(sprintf('nsqphp closing [%s]', (string)$connection));
            }
        }
    }
    
    /**
     * Subscribe to topic/channel
     *
     * @param string $topic A valid topic name: [.a-zA-Z0-9_-] and 1 < length < 32
     * @param string $channel Our channel name: [.a-zA-Z0-9_-] and 1 < length < 32
     *      "In practice, a channel maps to a downstream service consuming a topic."
     * @param callable $callback A callback that will be executed with a single
     *      parameter of the message object dequeued. Simply return TRUE to 
     *      mark the message as finished or throw an exception to cause a
     *      backed-off requeue
     * 
     * @throws \InvalidArgumentException If we don't have a valid callback
     */
    public function subscribe($topic, $channel, $callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                    '"callback" invalid; expecting a PHP callable'
                    );
        }
        
        // we need to instantiate a new connection for every nsqd that we need
        // to fetch messages from for this topic/channel

        $hosts = $this->nsLookup->lookupHosts($topic);
        if ($this->logger) {
            $this->logger->debug("Found the following hosts for topic \"$topic\": " . implode(',', $hosts));
        }

        foreach ($hosts as $host) {
            $parts = explode(':', $host);
            $conn = new Connection\Connection(
                    $parts[0],
                    isset($parts[1]) ? $parts[1] : NULL,
                    $this->connectionTimeout,
                    $this->readWriteTimeout,
                    $this->readWaitTimeout,
                    TRUE    // non-blocking
                    );
            if ($this->logger) {
                $this->logger->info("Connecting to {$host} and saying hello");
            }
            $conn->write($this->writer->magic());
            $this->connectionPool->add($conn);
            $socket = $conn->getSocket();
            $nsq = $this;
            $this->loop->addReadStream($socket, function ($socket) use ($nsq, $callback, $topic, $channel) {
                $nsq->readAndDispatchMessage($socket, $topic, $channel, $callback);
            });
            
            // subscribe
            $conn->write($this->writer->subscribe($topic, $channel, $this->shortId, $this->longId));
            $conn->write($this->writer->ready(1));
        }
    }

    /**
     * Read/dispatch callback for async sub loop
     * 
     * @param Resource $socket The socket that a message is available on
     * @param string $topic The topic subscribed to that yielded this message
     * @param string $channel The channel subscribed to that yielded this message
     * @param callable $callback The callback to execute to process this message
     */
    public function readAndDispatchMessage($socket, $topic, $channel, $callback)
    {
        $connection = $this->connectionPool->find($socket);
        $frame = $this->reader->readFrame($connection);

        if ($this->logger) {
            $this->logger->debug(sprintf('Read frame for topic=%s channel=%s [%s] %s', $topic, $channel, (string)$connection, json_encode($frame)));
        }

        // intercept errors/responses
        if ($this->reader->frameIsHeartbeat($frame)) {
            if ($this->logger) {
                $this->logger->debug(sprintf('HEARTBEAT [%s]', (string)$connection));
            }
            $connection->write($this->writer->nop());
        } elseif ($this->reader->frameIsMessage($frame)) {
            $msg = Message::fromFrame($frame);
            
            if ($this->dedupe !== NULL && $this->dedupe->containsAndAdd($topic, $channel, $msg)) {
                if ($this->logger) {
                    $this->logger->debug(sprintf('Deduplicating [%s] "%s"', (string)$connection, $msg->getId()));
                }
            } else {
                try {
                    call_user_func($callback, $msg);
                } catch (\Exception $e) {
                    if ($this->logger) {
                        $this->logger->warn(sprintf('Error processing [%s] "%s": %s', (string)$connection, $msg->getId(), $e->getMessage()));
                    }
                    // requeue message according to backoff strategy; continue
                    if ($this->requeueStrategy !== NULL
                            && ($delay = $this->requeueStrategy->shouldRequeue($msg)) !== NULL) {
                        // requeue
                        if ($this->logger) {
                            $this->logger->debug(sprintf('Requeuing [%s] "%s" with delay "%s"', (string)$connection, $msg->getId(), $delay));
                        }
                        $connection->write($this->writer->requeue($msg->getId(), $delay));
                        $connection->write($this->writer->ready(1));
                        continue;
                    } else {
                        if ($this->logger) {
                            $this->logger->debug(sprintf('Not requeuing [%s] "%s"', (string)$connection, $msg->getId()));
                        }
                    }
                }
            }
            
            // mark as done; get next on the way
            $connection->write($this->writer->finish($msg->getId()));
            $connection->write($this->writer->ready(1));

        } else {
            // @todo handle error responses a bit more cleverly
            throw new Exception\ProtocolException("Error/unexpected frame received: " . json_encode($frame), NULL, $e);
        }
    }
    
    /**
     * Run subscribe event loop
     */
    public function run()
    {
        $this->loop->run();
    }
}
