<?php

namespace Itinysun\Laraman\Server;

use App\Exceptions\Handler;
use App\Http\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class ApiServer
{

    protected static ?Worker $worker = null;

    protected Application $app;

    protected Kernel $kernel;

    /**
     * OnMessage.
     * @param TcpConnection|mixed $connection
     * @return null
     */
    public function onMessage(mixed $connection)
    {
        $request = Request::capture();
        try {
            $response = $this->getResponse($request);
            $this->send($connection, $response, $request);
        } catch (Throwable $e) {
            $resp = json_encode([
                'code'=>500,
                'data'=>['code'=>$e->getCode(),'msg'=>$e->getMessage()],
                'msg'=>'unhandled exception from server',
                'request_id'=>''
            ]);
            $this->send($connection, $resp, $request);
        }
        $this->prepareNextRequest();
        return null;
    }

    protected function prepareNextRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * OnWorkerStart.
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        static::$worker = $worker;
        $this->app = new LaramanApp(base_path());
        $this->app->singleton(
            \Illuminate\Contracts\Http\Kernel::class,
            Kernel::class
        );
        $this->app->singleton(
            ExceptionHandler::class,
            Handler::class
        );
        $this->kernel = $this->app->make(Kernel::class);
    }

    /**
     * Send.
     * @param TcpConnection|mixed $connection
     * @param string $response
     * @param Request $request
     * @return void
     */
    protected function send(mixed $connection, string $response, Request $request): void
    {
        $keepAlive = $request->header('connection');

        if (($keepAlive === null && $request->getProtocolVersion() === '1.1')
            || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive'
        ) {
            $connection->send($response);
            return;
        }
        $connection->close($response);
    }

    public function getResponse(Request $request): bool|string
    {
        ob_start();

        $response = $this->kernel->handle(
            $request
        );

        $response->send();

        $this->kernel->terminate($request, $response);

        return ob_get_clean();
    }
}
