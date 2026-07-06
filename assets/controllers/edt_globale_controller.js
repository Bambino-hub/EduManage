import { Controller } from '@hotwired/stimulus';

/*
 * Vue globale de l'emploi du temps : permet de déplacer une séance (ou un groupe de
 * matières parallèles, ex. Allemand/Espagnol, toujours ensemble) vers un autre créneau
 * pour LA MÊME CLASSE (clic puis clic sur une cible surlignée, ou glisser-déposer), en
 * ne proposant que des créneaux compatibles — professeur libre, salle libre, et règles
 * métier respectées (EPS jamais 4e/5e heure, FHR jamais vendredi après-midi, 8e heure
 * réservée au lycée lundi/jeudi). Cliquer une matière surligne aussi, à titre informatif,
 * toutes les autres classes du même enseignant.
 *
 * L'unité de sélection est toujours LE GROUPE de séances occupant la case cliquée (1
 * séance normalement, 2+ pour des matières parallèles) : les déplacer une par une
 * désynchroniserait un état qui doit rester groupé. Un groupe de plusieurs séances ne
 * peut être déposé que sur une case VIDE (pas d'échange multi-séances dans cette
 * version) ; une séance seule peut en revanche s'échanger avec une autre séance seule.
 * Les séances de classes fusionnées (data-fusion="1") restent, elles, non déplaçables.
 *
 * Tout reste en mémoire côté client jusqu'au clic sur "Enregistrer" (l'unique source de
 * vérité côté serveur revalide tout avant d'écrire en base) ; "Annuler" recharge
 * simplement la page pour revenir à l'état serveur, plus sûr qu'un rollback manuel du DOM.
 */
export default class extends Controller {
    static targets = ['cell', 'item', 'saveButton', 'cancelButton', 'pendingCount', 'alert'];
    static values = { saveUrl: String, token: String };

    connect() {
        this.pending = new Map(); // seanceId (string) => nouveau créneau id (string)
        this.selectedGroup = null; // Element[] — toutes les séances de la case sélectionnée

        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.selectedGroup) {
                this.clearHighlights();
                this.selectedGroup = null;
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

        if (this.selectedGroup && this.selectedGroup.includes(item)) {
            this.clearHighlights();
            this.selectedGroup = null;
            return;
        }

        this.selectItem(item);
    }

    selectItem(item) {
        this.clearHighlights();
        const group = this.itemsInCell(item.closest('td'));
        this.selectedGroup = group;
        group.forEach((i) => i.classList.add('edt-selected'));
        this.highlightTargets(group);
    }

    clearHighlights() {
        this.cellTargets.forEach((c) => c.classList.remove('edt-drop-valid', 'edt-drop-swap'));
        this.itemTargets.forEach((i) => i.closest('td')?.classList.remove('edt-prof-busy'));
        this.selectedGroup?.forEach((i) => i.classList.remove('edt-selected'));
    }

    // --- Glisser-déposer -------------------------------------------------------------

    dragStart(event) {
        const item = event.currentTarget;
        this.selectItem(item);
        this.selectedGroup.forEach((i) => i.classList.add('edt-dragging'));
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.seanceId);
    }

    dragEnd(event) {
        this.selectedGroup?.forEach((i) => i.classList.remove('edt-dragging'));
        this.clearHighlights();
        this.selectedGroup = null;
    }

    dragOver(event) {
        if (event.currentTarget.classList.contains('edt-drop-valid')) {
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
        if (!this.selectedGroup || !cell.classList.contains('edt-drop-valid')) {
            return;
        }

        this.moveGroupTo(this.selectedGroup, cell);
        this.clearHighlights();
        this.selectedGroup = null;
    }

    // --- Calcul des cibles valides ----------------------------------------------------

    /** @return {{busyEnseignant: Map<string, Map<string, Element>>, busySalle: Map<string, Map<string, Element>>}} */
    buildBusyMaps() {
        const busyEnseignant = new Map();
        const busySalle = new Map();

        this.itemTargets.forEach((it) => {
            const creneauId = it.dataset.creneauId;
            if (!busyEnseignant.has(creneauId)) busyEnseignant.set(creneauId, new Map());
            if (!busySalle.has(creneauId)) busySalle.set(creneauId, new Map());
            busyEnseignant.get(creneauId).set(it.dataset.enseignantId, it);
            busySalle.get(creneauId).set(it.dataset.salleId, it);
        });

        return { busyEnseignant, busySalle };
    }

    itemsInCell(cell) {
        return this.itemTargets.filter((it) => cell.contains(it));
    }

    /** Règles EPS / FHR / 8ème heure — miroir client de ReglesPlacementCreneau (PHP). */
    reglesRespectees(matiereCode, cellDataset) {
        const ordre = parseInt(cellDataset.ordre, 10);

        if (ordre >= 8 && !(cellDataset.cycle === 'lycee' && (cellDataset.jour === 'lundi' || cellDataset.jour === 'jeudi'))) {
            return false;
        }
        if (matiereCode === 'EPS' && (ordre === 4 || ordre === 5)) {
            return false;
        }
        if (matiereCode === 'FHR' && cellDataset.jour === 'vendredi' && cellDataset.apresMidi === '1') {
            return false;
        }

        return true;
    }

    /** Le groupe entier (1 ou plusieurs séances parallèles) peut-il vivre sur cette case, actuellement vide ? */
    groupeValidePourCibleVide(group, cellDataset, busyEnseignant, busySalle) {
        const targetCreneauId = cellDataset.creneauId;

        return group.every((item) => {
            if (!this.reglesRespectees(item.dataset.matiereCode, cellDataset)) {
                return false;
            }
            const enseignantOccupant = busyEnseignant.get(targetCreneauId)?.get(item.dataset.enseignantId);
            const salleOccupant = busySalle.get(targetCreneauId)?.get(item.dataset.salleId);
            if (enseignantOccupant && !group.includes(enseignantOccupant)) {
                return false;
            }
            if (salleOccupant && !group.includes(salleOccupant)) {
                return false;
            }
            return true;
        });
    }

    highlightTargets(group) {
        const { busyEnseignant, busySalle } = this.buildBusyMaps();
        const classeId = group[0].dataset.classeId;
        const sourceCreneauId = group[0].dataset.creneauId;
        const sourceCellDataset = group[0].closest('td').dataset;
        const enseignantIds = new Set(group.map((i) => i.dataset.enseignantId));

        // Surlignage informatif : toutes les autres séances des enseignants du groupe sélectionné.
        this.itemTargets.forEach((other) => {
            if (!group.includes(other) && enseignantIds.has(other.dataset.enseignantId)) {
                other.closest('td')?.classList.add('edt-prof-busy');
            }
        });

        this.cellTargets.forEach((cell) => {
            if (cell.dataset.classeId !== classeId || cell.dataset.creneauId === sourceCreneauId) {
                return;
            }

            const occupants = this.itemsInCell(cell);

            if (occupants.length === 0) {
                if (this.groupeValidePourCibleVide(group, cell.dataset, busyEnseignant, busySalle)) {
                    cell.classList.add('edt-drop-valid');
                }
                return;
            }

            // Case cible occupée : échange pris en charge uniquement séance-seule contre
            // séance-seule (pas d'échange impliquant un groupe de matières parallèles).
            if (group.length !== 1 || occupants.length !== 1) {
                return;
            }

            const item = group[0];
            const occupant = occupants[0];
            if (occupant.dataset.fusion === '1') {
                return;
            }
            if (!this.reglesRespectees(item.dataset.matiereCode, cell.dataset)) {
                return;
            }

            const targetCreneauId = cell.dataset.creneauId;
            const enseignantOccupantCible = busyEnseignant.get(targetCreneauId)?.get(item.dataset.enseignantId);
            const salleOccupantCible = busySalle.get(targetCreneauId)?.get(item.dataset.salleId);
            if ((enseignantOccupantCible && enseignantOccupantCible !== occupant) || (salleOccupantCible && salleOccupantCible !== occupant)) {
                return;
            }

            if (!this.reglesRespectees(occupant.dataset.matiereCode, sourceCellDataset)) {
                return;
            }
            const enseignantOccupantSource = busyEnseignant.get(sourceCreneauId)?.get(occupant.dataset.enseignantId);
            const salleOccupantSource = busySalle.get(sourceCreneauId)?.get(occupant.dataset.salleId);
            if ((enseignantOccupantSource && enseignantOccupantSource !== item) || (salleOccupantSource && salleOccupantSource !== item)) {
                return;
            }

            cell.classList.add('edt-drop-valid', 'edt-drop-swap');
        });
    }

    // --- Application du déplacement (état client, en attente d'enregistrement) -------

    moveGroupTo(group, targetCell) {
        const sourceCell = group[0].closest('td');
        const sourceCreneauId = sourceCell.dataset.creneauId;
        const targetCreneauId = targetCell.dataset.creneauId;
        const occupant = group.length === 1 ? (this.itemsInCell(targetCell)[0] ?? null) : null;

        group.forEach((item) => {
            targetCell.appendChild(item);
            item.dataset.creneauId = targetCreneauId;
            this.stagePending(item, targetCreneauId);
        });

        if (occupant) {
            sourceCell.appendChild(occupant);
            occupant.dataset.creneauId = sourceCreneauId;
            this.stagePending(occupant, sourceCreneauId);
        }

        this.toggleEmptyPlaceholder(sourceCell);
        this.toggleEmptyPlaceholder(targetCell);
        this.updatePendingCount();
    }

    stagePending(item, creneauId) {
        this.pending.set(item.dataset.seanceId, creneauId);
        item.classList.add('edt-pending');
    }

    toggleEmptyPlaceholder(cell) {
        const hasItems = this.itemsInCell(cell).length > 0;
        let placeholder = cell.querySelector('.edt-empty');

        if (hasItems) {
            placeholder?.remove();
        } else if (!placeholder) {
            placeholder = document.createElement('span');
            placeholder.className = 'text-muted edt-empty';
            placeholder.textContent = '·';
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

        const changes = Array.from(this.pending.entries()).map(([seanceId, creneauId]) => ({
            seanceId: parseInt(seanceId, 10),
            creneauId: parseInt(creneauId, 10),
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
