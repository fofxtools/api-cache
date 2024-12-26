abstract class BaseApiClient
{
    protected RequestHandler $requestHandler;
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->requestHandler = new RequestHandler(
            new CacheManager(),
            new RateLimiter()
        );
    }
    
    public function get($input = null, array $options = [])
    {
        // Default implementation
    }
    
    abstract protected function getDefaultEndpoint(): string;
    abstract protected function getDefaultInputParam(): string;
} 