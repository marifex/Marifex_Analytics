<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GLPIKey;
use GlpiPlugin\Marifex\HomeDashboardTab;
use GlpiPlugin\Marifex\Profile;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/Dashboard', name: 'marifex_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new RedirectResponse('/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241');
    }

    /**
     * Chrome-free version of the same dashboard, for the Android app's
     * WebView. Gated by the same Profile::canView() right as the in-GLPI
     * tab and every /api/metrics/* call - no separate access rule to
     * maintain.
     *
     * Opts out of GLPI's default Firewall check (which would otherwise run before this
     * method even starts) so resumeAppSession() gets a chance to authenticate the request
     * from the app's own token first; checkCentralAccess() below then re-applies that same
     * check manually, so a request that arrives with no/invalid token behaves exactly like
     * it did before - redirected to GLPI's login form, same as any other protected page.
     */
    #[Route('/Dashboard/Mobile', name: 'marifex_dashboard_mobile', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function mobile(Request $request): Response
    {
        $this->resumeAppSession($request);
        Session::checkCentralAccess();

        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }
        // Passed here rather than set inside the template - layout/parts/head.html.twig
        // expects these in its rendering context from the first line, and a `{% set %}`
        // inside mobile_embed.html.twig doesn't reliably reach it through the include.
        $headParameters = [
            'is_anonymous_page' => false,
            'css_files' => [
                ['path' => 'lib/base.css'],
                ['path' => 'lib/tabler.css'],
                ['path' => 'lib/gridstack.css'],
                ['path' => 'css/glpi.scss'],
                ['path' => 'css/core_palettes.scss'],
            ],
            'js_files' => [
                ['path' => 'lib/base.js'],
                ['path' => 'js/common.js'],
            ],
            'js_modules' => [],
            'custom_header_tags' => [],
            // The bare page does not use GLPI's normal footer, so its plugin bundle is
            // passed separately and rendered after the dashboard mount point exists.
            'dashboard_js_files' => [
                ['path' => 'lib/gridstack.js'],
                [
                    'path' => 'plugins/marifex/js/dashboard.js',
                    'options' => ['version' => PLUGIN_MARIFEX_VERSION],
                ],
            ],
            'title' => __('Analytics', 'marifex'),
        ];
        return $this->render('@marifex/dashboard/mobile_embed.html.twig', $headParameters + HomeDashboardTab::embedData());
    }

    /**
     * Lets the Android app skip GLPI's login form for this one route. The app already
     * holds a GLPI REST API "Session-Token" from its own sign-in - that token isn't a
     * separate credential, it's the app's PHP session ID (session_id()), encrypted with
     * GLPI's own GLPIKey (see Glpi\Api\API::initSession() / retrieveSession() in core,
     * which do the exact same encrypt/decrypt round-trip for apirest.php calls).
     *
     * If the WebView's request carries that same token as ?session_token=..., decrypt
     * it and resume that exact PHP session here, so the user lands on the dashboard
     * already authenticated - no separate GLPI web login, no password stored on-device.
     * Wrong/expired/missing token just falls through to GLPI's normal login-required
     * behaviour further down, so this can't weaken access below what it is today.
     */
    private function resumeAppSession(Request $request): void
    {
        if (Session::getLoginUserID() !== false) {
            return;
        }

        $token = $request->query->get('session_token');
        if (empty($token)) {
            return;
        }

        $decoded = base64_decode(trim($token), true);
        if ($decoded === false) {
            return;
        }

        $sessionId = (new GLPIKey())->decrypt($decoded);
        if (empty($sessionId)) {
            return;
        }

        if ($sessionId !== session_id()) {
            // session_id() can only be changed while no session is active - by this
            // point in the Symfony request cycle GLPI has usually already auto-started
            // an anonymous session, so close it out first (session_destroy() alone
            // does not reset session_status() back to PHP_SESSION_NONE).
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            session_id($sessionId);
            Session::start();
            Session::loadLanguage();
            // API::initSession() strips valid_id right after encrypting it into the
            // token, since apirest.php calls validate the token itself instead. Web
            // pages/AJAX rely on checkValidSessionId() seeing it match session_id(),
            // so restore it here - otherwise the very next authenticated request
            // (e.g. the dashboard's own /api/metrics/* calls) would be treated as an
            // expired session and bounce back to the login form.
            $_SESSION['valid_id'] = session_id();
        }
    }
}

