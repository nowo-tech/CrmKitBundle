<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginType;
use Nowo\UiKitBundle\View\AuthPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Demo home (public) and the form login.
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        return $this->render('demo/home.html.twig', [
            'worker' => $request->server->has('FRANKENPHP_WORKER'),
            'people' => [
                ['user' => 'ana', 'name' => 'Ana Viewer', 'roles' => 'ROLE_USER = crm view: lists, 360° pages, vCards'],
                ['user' => 'mia', 'name' => 'Mia Sales', 'roles' => 'ROLE_SALES = crm edit: companies, contacts, notes, tags, links, import, merge'],
                ['user' => 'admin', 'name' => 'Ada Admin', 'roles' => 'ROLE_ADMIN = crm manage: tags, custom fields, settings, CSV export, GDPR export and erasure'],
            ],
        ]);
    }

    #[Route('/login', name: 'demo_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        $form = $this->createForm(LoginType::class, ['_username' => $auth->getLastUsername()]);

        return $this->render('@NowoUiKit/page/auth.html.twig', ['page' => new AuthPage(
            'Sign in',
            $form->createView(),
            'Demo users: ana, mia, admin (password "demo").',
            'CRM Kit demo',
            $auth->getLastAuthenticationError() !== null ? 'The username or password is incorrect.' : null,
            'Sign in',
            formAttributes: ['data-turbo' => 'false', 'data-testid' => 'login-form'],
            documentTitle: 'Sign in',
        )], new Response(status: $auth->getLastAuthenticationError() !== null ? 401 : 200));
    }

    #[Route('/logout', name: 'demo_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }
}
