<?php

declare(strict_types=1);

namespace App\Security\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Déconnecte automatiquement un utilisateur (admin ou enseignant) resté inactif trop
 * longtemps. Symfony ne propose rien de tel nativement : `session.gc_maxlifetime` n'est
 * qu'un seuil d'éligibilité au nettoyage probabiliste du garbage collector PHP, pas une
 * règle déterministe par utilisateur — d'où ce listener, basé sur le "dernier usage" que
 * Symfony trace lui-même dans les métadonnées de session.
 *
 * Priorité 0 : after Symfony's own firewall (priority 8) so the token is fully hydrated
 * from the session, but before the controller runs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
class IdleSessionTimeoutListener
{
    private const MAX_IDLE_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasPreviousSession() || $this->security->getUser() === null) {
            return;
        }

        $session  = $request->getSession();
        $idleFor  = time() - $session->getMetadataBag()->getLastUsed();

        if ($idleFor < self::MAX_IDLE_SECONDS) {
            return;
        }

        $session->invalidate();

        $response = new RedirectResponse($this->urlGenerator->generate('security_login', ['session_expiree' => 1]));
        $response->headers->clearCookie($session->getName());
        $event->setResponse($response);
    }
}
