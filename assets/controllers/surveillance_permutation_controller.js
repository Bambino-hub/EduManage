import { Controller } from '@hotwired/stimulus';

/*
 * Tableau de surveillance : permet de réaffecter un enseignant vers une autre classe, MÊME
 * D'UN AUTRE EXAMEN (clic puis clic sur une case surlignée, ou glisser-déposer). Contrairement à
 * un échange au sein du même examen (même horaire par construction), changer d'examen peut
 * introduire un nouveau conflit de disponibilité (cours normal ou autre surveillance) — c'est le
 * serveur (SurveillancePermutationService) qui revérifie cette disponibilité avant d'écrire,
 * jamais uniquement ce contrôleur client qui n'est qu'une aide visuelle. Seul le regroupement de
 * classes (data-fusion="1") bloque un déplacement, comme les classes fusionnées de l'emploi du
 * temps — que ce soit la case d'origine ou la case cible.
 *
 * Une case vide ("non pourvu") est une cible valide (comble le poste) ; une case occupée par UN
 * SEUL enseignant est une cible d'échange valide ; une case avec plusieurs enseignants (plusieurs
 * surveillants par classe) n'est pas une cible d'échange dans cette version, trop ambigu.
 *
 * Tout reste en mémoire côté client jusqu'au clic sur "Enregistrer" (l'unique source de vérité
 * côté serveur revalide tout avant d'écrire en base) ; "Annuler" recharge simplement la page.
 */
export default class extends Controller {
    static targets = ['cell', 'item', 'saveButton', 'cancelButton', 'pendingCount', 'alert'];
    static values = { saveUrl: String, token: String };

    connect() {
        this.pending = new Map(); // surveillanceId (string) => nouvelle classe id (string)
        this.selectedItem = null;

        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.selectedItem) {
                this.clearHighlights();
                this.selectedItem = null;
            }
        };
        document.addEventListener('keydown', this.onKeydown);

        this.onBeforeUnload = (event) => {
            if (this.pending.size > 0) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
    }

    // --- Sélection / désélection ---------------------------------------------------

    toggleSelect(event) {
        event.stopPropagation();
        const item = event.currentTarget;
        if (item.dataset.fusion === '1') {
            return;
        }

        if (this.selectedItem === item) {
            this.clearHighlights();
            this.selectedItem = null;
            return;
        }

        this.selectItem(item);
    }

    selectItem(item) {
        this.clearHighlights();
        this.selectedItem = item;
        item.classList.add('surv-selected');
        this.highlightTargets(item);
    }

    clearHighlights() {
        this.cellTargets.forEach((c) => c.classList.remove('surv-drop-valid', 'surv-drop-swap'));
        this.selectedItem?.classList.remove('surv-selected');
    }

    // --- Glisser-déposer -------------------------------------------------------------

    dragStart(event) {
        const item = event.currentTarget;
        if (item.dataset.fusion === '1') {
            event.preventDefault();
            return;
        }
        this.selectItem(item);
        item.classList.add('surv-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.surveillanceId);
    }

    dragEnd() {
        this.selectedItem?.classList.remove('surv-dragging');
        this.clearHighlights();
        this.selectedItem = null;
    }

    dragOver(event) {
        if (event.currentTarget.classList.contains('surv-drop-valid')) {
            event.preventDefault();
        }
    }

    drop(event) {
        event.preventDefault();
        this.attemptMoveTo(event.currentTarget);
    }

    // --- Clic sur une case cible (alternative accessible au glisser-déposer) ---------

    cellClick(event) {
        this.attemptMoveTo(event.currentTarget);
    }

    attemptMoveTo(cell) {
        if (!this.selectedItem || !cell.classList.contains('surv-drop-valid')) {
            return;
        }

        this.moveItemTo(this.selectedItem, cell);
        this.clearHighlights();
        this.selectedItem = null;
    }

    // --- Calcul des cibles valides ----------------------------------------------------

    itemsInCell(cell) {
        return this.itemTargets.filter((it) => cell.contains(it));
    }

    highlightTargets(item) {
        const sourceCell = item.closest('[data-surveillance-permutation-target~="cell"]');

        this.cellTargets.forEach((cell) => {
            if (cell === sourceCell || cell.dataset.fusion === '1') {
                return;
            }

            const occupants = this.itemsInCell(cell);

            if (occupants.length === 0) {
                cell.classList.add('surv-drop-valid');
                return;
            }

            if (occupants.length === 1 && occupants[0].dataset.fusion !== '1') {
                cell.classList.add('surv-drop-valid', 'surv-drop-swap');
            }
        });
    }

    // --- Application du déplacement (état client, en attente d'enregistrement) -------

    moveItemTo(item, targetCell) {
        const sourceCell = item.closest('[data-surveillance-permutation-target~="cell"]');
        const occupant = this.itemsInCell(targetCell)[0] ?? null;

        this.placeItem(item, targetCell);
        if (occupant) {
            this.placeItem(occupant, sourceCell);
        }

        this.toggleEmptyPlaceholder(sourceCell);
        this.toggleEmptyPlaceholder(targetCell);
        this.updatePendingCount();
    }

    placeItem(item, cell) {
        const emptyPlaceholder = cell.querySelector('.surveillance-empty');
        emptyPlaceholder?.remove();
        cell.appendChild(item);
        this.stagePending(item, cell.dataset.classeId, cell.dataset.examenId);
    }

    stagePending(item, classeId, examenId) {
        this.pending.set(item.dataset.surveillanceId, { classeId, examenId });
        item.classList.add('surv-pending');
    }

    toggleEmptyPlaceholder(cell) {
        if (this.itemsInCell(cell).length > 0) {
            return;
        }
        if (!cell.querySelector('.surveillance-empty')) {
            const placeholder = document.createElement('span');
            placeholder.className = 'text-warning surveillance-empty';
            placeholder.textContent = 'non pourvu';
            cell.appendChild(placeholder);
        }
    }

    updatePendingCount() {
        const n = this.pending.size;
        this.saveButtonTarget.disabled = n === 0;
        this.cancelButtonTarget.disabled = n === 0;
        this.pendingCountTarget.textContent = n === 0 ? '' : `${n} modification${n > 1 ? 's' : ''} en attente`;
    }

    // --- Enregistrement / annulation --------------------------------------------------

    async enregistrer() {
        if (this.pending.size === 0) {
            return;
        }

        this.hideAlert();
        this.saveButtonTarget.disabled = true;
        this.cancelButtonTarget.disabled = true;

        const changes = Array.from(this.pending.entries()).map(([surveillanceId, cible]) => ({
            surveillanceId: parseInt(surveillanceId, 10),
            classeId: parseInt(cible.classeId, 10),
            examenId: parseInt(cible.examenId, 10),
        }));

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _token: this.tokenValue, changes }),
            });
            const data = await response.json();

            if (response.ok && data.succes) {
                this.pending.clear();
                window.location.reload();
                return;
            }

            this.showAlert((data.erreurs && data.erreurs.length ? data.erreurs : ['Modification refusée.']).join(' '));
        } catch (error) {
            this.showAlert('Erreur réseau, veuillez réessayer.');
        } finally {
            this.updatePendingCount();
        }
    }

    annuler() {
        if (this.pending.size === 0) {
            return;
        }
        if (window.confirm('Annuler toutes les modifications non enregistrées et recharger la page ?')) {
            this.pending.clear();
            window.location.reload();
        }
    }

    showAlert(message) {
        this.alertTarget.textContent = message;
        this.alertTarget.classList.remove('d-none');
    }

    hideAlert() {
        this.alertTarget.classList.add('d-none');
    }
}
