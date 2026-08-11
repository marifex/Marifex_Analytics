<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Palette\PaletteService;
use GlpiPlugin\Marifex\Profile;
use InvalidArgumentException;
use JsonException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PaletteController extends AbstractController
{
    #[Route('/api/palettes', name: 'marifex_palettes', methods: ['GET','POST','PUT','DELETE'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!Profile::canView()) throw new AccessDeniedHttpException();
        if (!$request->isMethod('GET') && !Profile::canAdminister()) throw new AccessDeniedHttpException();
        try {
            if (!$request->isMethod('GET')) Session::checkCSRF(['_glpi_csrf_token' => $request->headers->get('X-GLPI-CSRF-Token')], true);
            $payload = $request->getContent() === '' ? [] : json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new InvalidArgumentException('Invalid palette payload.');
            $service = new PaletteService();
            if ($request->isMethod('GET')) $result = $service->catalogue();
            elseif ($request->isMethod('PUT')) $result = $service->update((int) ($payload['id'] ?? 0), (array) ($payload['palette'] ?? []), (bool) ($payload['confirmed'] ?? false));
            elseif ($request->isMethod('DELETE')) $result = $service->delete((int) ($payload['id'] ?? 0), (string) ($payload['replacement'] ?? ''), (bool) ($payload['confirmed'] ?? false));
            else $result = match ((string) ($payload['action'] ?? 'create')) {
                'create', 'duplicate' => $service->create((array) ($payload['palette'] ?? [])),
                'import' => $service->import((string) ($payload['json'] ?? '')),
                'default' => $service->setDefault((string) ($payload['key'] ?? '')),
                default => throw new InvalidArgumentException('Unsupported palette operation.'),
            };
            return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (InvalidArgumentException|JsonException $exception) { throw new BadRequestHttpException($exception->getMessage(), $exception); }
    }
}
