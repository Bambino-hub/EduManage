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
 * désynchroniserait un état qui doit rester groupé. Un groupe peut être déposé sur une
 * case VIDE, ou ÉCHANGÉ avec les séances d'une case déjà occupée quelle que soit la
 * taille de chaque côté (1 séance contre 1, groupe contre 1, groupe contre groupe) —
 * seule une séance de classes fusionnées n'est jamais un partenaire d'échange valide
 * pour une séance d'UNE seule classe (il faudrait bouger les autres colonnes aussi).
 *
 * Les séances de classes fusionnées (data-fusion="1", ex. 1ère C / 1ère D1) suivent une
 * logique à part : le "groupe" n'est plus les séances d'UNE case, mais toutes les
 * séances qui partagent le même data-regroupement-id au même créneau, RÉPARTIES SUR
 * PLUSIEURS COLONNES (une par classe fusionnée) — elles représentent une seule séance
 * pédagogique vécue par 2+ classes ensemble, même enseignant, même salle. Le groupe se
 * déplace vers des cases vides, ou s'échange avec ce qui occupe les cases de ses classes
 * au créneau cible (ex. FR 1ère C/1ère D1 ↔ PHILO 1ère C/1ère D1) ; voir
 * highlightFusionTargets()/moveFusionGroupTo().
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

        // Clic sur une séance d'une case cible surlignée : c'est un échange, pas une nouvelle sélection.
        if (this.selectedGroup && item.closest('td')?.classList.contains('edt-drop-valid')) {
            this.attemptMoveTo(item.closest('td'));
            return;
        }

        this.selectItem(item);
    }

    selectItem(item) {
        this.clearHighlights();
        const group = item.dataset.fusion === '1' ? this.itemsInFusionGroup(item) : this.itemsInCell(item.closest('td'));
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

    /** Toutes les séances d'un même regroupement (classes fusionnées) au même créneau que `item`. */
    itemsInFusionGroup(item) {
        const regroupementId = item.dataset.regroupementId;
        const creneauId = item.dataset.creneauId;
        return this.itemTargets.filter((it) => it.dataset.regroupementId === regroupementId && it.dataset.creneauId === creneauId);
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
        if (group[0].dataset.fusion === '1') {
            this.highlightFusionTargets(group);
            return;
        }

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

            // Case cible occupée : échange group ↔ occupants, quelle que soit la taille
            // de chaque côté (1 séance seule, ou un groupe de matières parallèles) — sauf
            // si l'un des occupants est une classe fusionnée (jamais échangeable ici).
            if (occupants.some((o) => o.dataset.fusion === '1')) {
                return;
            }
            if (!group.every((item) => this.reglesRespectees(item.dataset.matiereCode, cell.dataset))) {
                return;
            }
            if (!occupants.every((o) => this.reglesRespectees(o.dataset.matiereCode, sourceCellDataset))) {
                return;
            }

            const targetCreneauId = cell.dataset.creneauId;
            const cibleOk = group.every((item) => {
                const ens = busyEnseignant.get(targetCreneauId)?.get(item.dataset.enseignantId);
                const sal = busySalle.get(targetCreneauId)?.get(item.dataset.salleId);
                return (!ens || occupants.includes(ens)) && (!sal || occupants.includes(sal));
            });
            if (!cibleOk) {
                return;
            }

            const sourceOk = occupants.every((o) => {
                const ens = busyEnseignant.get(sourceCreneauId)?.get(o.dataset.enseignantId);
                const sal = busySalle.get(sourceCreneauId)?.get(o.dataset.salleId);
                return (!ens || group.includes(ens)) && (!sal || group.includes(sal));
            });
            if (!sourceOk) {
                return;
            }

            cell.classList.add('edt-drop-valid', 'edt-drop-swap');
        });
    }

    /**
     * Cibles valides pour un groupe de classes fusionnées : le groupe est réparti sur
     * PLUSIEURS colonnes (une par classe fusionnée) au créneau source. Pour un créneau
     * cible donné, on considère la case de CHAQUE classe du groupe :
     * - toutes vides → simple déplacement ;
     * - une ou plusieurs occupées → ÉCHANGE : chaque occupant redescend dans la case de
     *   SA classe au créneau source. Il faut que ça "corresponde" : un occupant lui-même
     *   fusionné (ex. PHILO 1ère C / 1ère D1 face à FR 1ère C / 1ère D1) n'est échangeable
     *   que si TOUT son groupe fusionné est dans ces cases, sinon on scinderait sa fusion.
     * Dans tous les cas les règles EPS/FHR/8e heure doivent passer pour chaque séance sur
     * sa nouvelle case, et aucun enseignant/salle ne doit être déjà pris par une séance
     * extérieure à l'échange.
     */
    highlightFusionTargets(group) {
        const { busyEnseignant, busySalle } = this.buildBusyMaps();
        const sourceCreneauId = group[0].dataset.creneauId;
        const enseignantIds = new Set(group.map((i) => i.dataset.enseignantId));

        this.itemTargets.forEach((other) => {
            if (!group.includes(other) && enseignantIds.has(other.dataset.enseignantId)) {
                other.closest('td')?.classList.add('edt-prof-busy');
            }
        });

        const creneauIds = new Set(this.cellTargets.map((c) => c.dataset.creneauId));
        creneauIds.forEach((creneauId) => {
            if (creneauId === sourceCreneauId) {
                return;
            }

            const cellsCibles = group.map((item) => this.findCell(item.dataset.classeId, creneauId));
            if (cellsCibles.some((c) => !c)) {
                return; // classe introuvable à ce créneau
            }
            const occupants = cellsCibles.flatMap((c) => this.itemsInCell(c));

            // Un occupant fusionné doit arriver avec tout son groupe, sinon pas d'échange possible.
            if (occupants.some((o) => o.dataset.fusion === '1' && !this.itemsInFusionGroup(o).every((m) => occupants.includes(m)))) {
                return;
            }
            if (group.some((item, i) => !this.reglesRespectees(item.dataset.matiereCode, cellsCibles[i].dataset))) {
                return;
            }
            if (!occupants.every((o) => this.reglesRespectees(o.dataset.matiereCode, this.findCell(o.dataset.classeId, sourceCreneauId).dataset))) {
                return;
            }

            const cibleOk = group.every((item) => {
                const ens = busyEnseignant.get(creneauId)?.get(item.dataset.enseignantId);
                const sal = busySalle.get(creneauId)?.get(item.dataset.salleId);
                return (!ens || occupants.includes(ens)) && (!sal || occupants.includes(sal));
            });
            const sourceOk = occupants.every((o) => {
                const ens = busyEnseignant.get(sourceCreneauId)?.get(o.dataset.enseignantId);
                const sal = busySalle.get(sourceCreneauId)?.get(o.dataset.salleId);
                return (!ens || group.includes(ens)) && (!sal || group.includes(sal));
            });
            if (!cibleOk || !sourceOk) {
                return;
            }

            cellsCibles.forEach((c) => c.classList.add('edt-drop-valid'));
            if (occupants.length > 0) {
                cellsCibles.forEach((c) => c.classList.add('edt-drop-swap'));
            }
        });
    }

    findCell(classeId, creneauId) {
        return this.cellTargets.find((c) => c.dataset.creneauId === creneauId && c.dataset.classeId === classeId);
    }

    // --- Application du déplacement (état client, en attente d'enregistrement) -------

    moveGroupTo(group, targetCell) {
        if (group[0].dataset.fusion === '1') {
            this.moveFusionGroupTo(group, targetCell);
            return;
        }

        const sourceCell = group[0].closest('td');
        const sourceCreneauId = sourceCell.dataset.creneauId;
        const targetCreneauId = targetCell.dataset.creneauId;
        const occupants = this.itemsInCell(targetCell);

        group.forEach((item) => {
            targetCell.appendChild(item);
            item.dataset.creneauId = targetCreneauId;
            this.stagePending(item, targetCreneauId);
        });

        occupants.forEach((occupant) => {
            sourceCell.appendChild(occupant);
            occupant.dataset.creneauId = sourceCreneauId;
            this.stagePending(occupant, sourceCreneauId);
        });

        this.toggleEmptyPlaceholder(sourceCell);
        this.toggleEmptyPlaceholder(targetCell);
        this.updatePendingCount();
    }

    /**
     * Déplace chaque membre du groupe fusionné vers SA PROPRE colonne au créneau cible
     * (targetCell n'est que la case cliquée/déposée, une parmi celles du groupe) ; les
     * éventuels occupants de ces cases redescendent, chacun dans sa colonne, au créneau source.
     */
    moveFusionGroupTo(group, targetCell) {
        const sourceCreneauId = group[0].dataset.creneauId;
        const targetCreneauId = targetCell.dataset.creneauId;
        const sourceCells = group.map((item) => item.closest('td'));
        const cellsCibles = group.map((item) => this.findCell(item.dataset.classeId, targetCreneauId));
        const occupants = cellsCibles.flatMap((c) => this.itemsInCell(c));

        group.forEach((item, i) => {
            cellsCibles[i].appendChild(item);
            item.dataset.creneauId = targetCreneauId;
            this.stagePending(item, targetCreneauId);
        });

        occupants.forEach((occupant) => {
            this.findCell(occupant.dataset.classeId, sourceCreneauId).appendChild(occupant);
            occupant.dataset.creneauId = sourceCreneauId;
            this.stagePending(occupant, sourceCreneauId);
        });

        [...sourceCells, ...cellsCibles].forEach((c) => this.toggleEmptyPlaceholder(c));
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
