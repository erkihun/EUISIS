<?php

declare(strict_types=1);

namespace Modules\Court\Application\Queries;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Modules\Court\Contracts\CourtIntegration;
use Modules\Court\Contracts\Data\CourtAnswer;
use Modules\Court\Domain\Models\IntegrationClient;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Runs the court's integration action for a path directly: the action is
 * found among the court's integration routes and called with the data, as
 * EUISIS itself (IntegrationClient::inProcess). No HTTP kernel, middleware,
 * token or network is involved; validation and the court's rules are the
 * action's own.
 */
final class CourtIntegrationGateway implements CourtIntegration
{
    private const PREFIX = '/court/api/v1/integration';

    public function __construct(private readonly Router $router) {}

    public function call(string $method, string $path, array $data = [], array $files = []): CourtAnswer
    {
        $request = Request::create(self::PREFIX.'/'.ltrim($path, '/'), strtoupper($method), $data, [], $this->uploads($files), ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
        $request->attributes->set('integration_client', IntegrationClient::inProcess());

        $app = app();
        $outer = $app->bound('request') ? $app['request'] : null;
        $app->instance('request', $request);
        Facade::clearResolvedInstance('request');

        try {
            $route = $this->router->getRoutes()->match($request);
            if (! str_starts_with($route->uri(), ltrim(self::PREFIX, '/'))) {
                return new CourtAnswer(404, (string) json_encode(['message' => 'Not found.']));
            }
            $request->setRouteResolver(fn () => $route);
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $response = $this->router->prepareResponse($request, $route->run());
        } catch (ModelNotFoundException) {
            return new CourtAnswer(404, (string) json_encode(['message' => 'Not found.']));
        } catch (ValidationException $e) {
            return new CourtAnswer(422, (string) json_encode(['message' => $e->getMessage(), 'errors' => $e->errors()]));
        } catch (HttpExceptionInterface $e) {
            return new CourtAnswer($e->getStatusCode(), (string) json_encode(['message' => $e->getMessage() ?: Response::$statusTexts[$e->getStatusCode()] ?? 'Error']));
        } finally {
            if ($outer !== null) {
                $app->instance('request', $outer);
                Facade::clearResolvedInstance('request');
            }
        }

        return new CourtAnswer($response->getStatusCode(), $this->body($response), array_filter([
            'content-type' => (string) $response->headers->get('Content-Type', 'application/json'),
            'content-disposition' => $response->headers->get('Content-Disposition'),
        ]));
    }

    private function body(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    /** @return array<string, UploadedFile|list<UploadedFile>> */
    private function uploads(array $files): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            $out[$field] = array_is_list($file) ? array_map($this->upload(...), $file) : $this->upload($file);
        }

        return $out;
    }

    /** @param array{name: string, contents: string, mime?: string} $file */
    private function upload(array $file): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'court');
        file_put_contents($path, $file['contents']);

        return new UploadedFile($path, $file['name'], $file['mime'] ?? null, null, true);
    }
}
