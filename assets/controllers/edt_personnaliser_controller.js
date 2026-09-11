import { Controller } from '@hotwired/stimulus';

/*
 * Page « Personnaliser » : emploi du temps d'UN SEUL enseignant, où l'on peut déplacer
 * une séance (clic puis clic sur une autre case, ou glisser-déposer) et verrouiller une
 * séance pour la protéger de toute réorganisation future (EmploiDuTempsGenerator::reorganiser()).
 *
 * Contrairement à la vue globale (edt_globale_controller.js), pas de précalcul côté
 * client des cases valides — et pour cause : le déplacement ici est volontairement
 * LIBRE, sans vérification de conflit (cf. EmploiDuTempsPersonnalisationService::deplacer()),
 * donc aucune case n'est jamais "invalide" à précalculer. Chaque déplacement est envoyé
 * au serveur immédiatement, un par un — pas de lot en attente — et la page se recharge
 * après un succès pour refléter le nouvel état (et une éventuelle cascade sur des
 * séances sœurs — classes fusionnées / matière parallèle — qui doivent toujours
 * partager le même créneau).
 */
export default class extends Controller {
    static targets = ['cell', 'item', 'alert'];
    static values = { moveUrl: String, moveToken: String, lockUrl: String, lockToken: String };

    connect() {
        this.selected = null; // Element (séance) actuellement sélectionnée pour déplacement

        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.clearSelection();
            }
        };
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    // --- Sélection / déplacement ------------------------------------------------------

    toggleSelect(event) {
        event.stopPropagation();
        const item = event.currentTarget;

        // Une séance est déjà sélectionnée et on clique sur une AUTRE séance : le clic
        // dépose la sélection sur SA case plutôt que de changer la sélection — la case
        // cible peut très bien être déjà occupée (déplacement libre, sans vérification
        // de conflit, cf. EmploiDuTempsPersonnalisationService::deplacer()). Sans ce
        // cas, cliquer une case occupée est impossible : le clic atterrit toujours sur
        // la séance qui s'y trouve, jamais sur la case elle-même.
        if (this.selected && this.selected !== item) {
            this.attemptMove(item.closest('td'));
            return;
        }

        if (item.dataset.verrouille === '1') {
            this.showAlert('Séance verrouillée : déverrouillez-la d\'abord (cadenas) pour la déplacer.');
            return;
        }

        // Recliquer sur la séance déjà sélectionnée ne fait rien (au lieu de la
        // désélectionner) : un double-clic — deux clics rapides sur la MÊME séance —
        // ne doit jamais annuler silencieusement la sélection qu'il vient de faire.
        // Pour annuler : Échap, ou sélectionner une autre séance.
        if (this.selected === item) {
            return;
        }

        this.clearSelection();
        this.selected = item;
        item.classList.add('edtp-selected');
    }

    clearSelection() {
        this.selected?.classList.remove('edtp-selected');
        this.selected = null;
    }

    dragStart(event) {
        if (event.currentTarget.dataset.verrouille === '1') {
            event.preventDefault();
            return;
        }
        this.clearSelection();
        this.selected = event.currentTarget;
        this.selected.classList.add('edtp-selected');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.selected.dataset.seanceId);
    }

    dragEnd() {
        this.clearSelection();
    }

    dragOver(event) {
        event.preventDefault();
    }

    drop(event) {
        event.preventDefault();
        this.attemptMove(event.currentTarget);
    }

    cellClick(event) {
        if (!this.selected) {
            return;
        }
        this.attemptMove(event.currentTarget);
    }

    async attemptMove(cell) {
        if (!this.selected) {
            return;
        }
        if (this.selected.closest('td') === cell) {
            this.clearSelection();
            return;
        }

        const seanceId  = parseInt(this.selected.dataset.seanceId, 10);
        const creneauId = parseInt(cell.dataset.creneauId, 10);
        this.clearSelection();
        this.hideAlert();

        try {
            const response = await fetch(this.moveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _token: this.moveTokenValue,
                    seanceId,
                    creneauId,
                }),
            });
            const data = await response.json();

            if (response.ok && data.succes) {
                window.location.reload();
                return;
            }

            this.showAlert((data.erreurs && data.erreurs.length ? data.erreurs : ['Déplacement refusé.']).join(' '));
        } catch (error) {
            this.showAlert('Erreur réseau, veuillez réessayer.');
        }
    }

    // --- Verrouillage --------------------------------------------------------------

    async toggleLock(event) {
        event.stopPropagation();
        const button = event.currentTarget;
        const item = button.closest('[data-edt-personnaliser-target="item"]');
        const verrouille = item.dataset.verrouille !== '1';

        this.hideAlert();
        button.disabled = true;

        try {
            const response = await fetch(this.lockUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _token: this.lockTokenValue,
                    seanceId: parseInt(item.dataset.seanceId, 10),
                    verrouille,
                }),
            });
            const data = await response.json();

            if (response.ok && data.succes) {
                // Recharge : reflète correctement une éventuelle cascade (matières
                // parallèles / classes fusionnées verrouillées ensemble).
                window.location.reload();
                return;
            }

            this.showAlert((data.erreurs && data.erreurs.length ? data.erreurs : ['Action refusée.']).join(' '));
        } catch (error) {
            this.showAlert('Erreur réseau, veuillez réessayer.');
        } finally {
            button.disabled = false;
        }
    }

    // --- Alerte --------------------------------------------------------------------

    showAlert(message) {
        this.alertTarget.textContent = message;
        this.alertTarget.classList.remove('d-none');
    }

    hideAlert() {
        this.alertTarget.classList.add('d-none');
    }
}
