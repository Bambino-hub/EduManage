import { Controller } from '@hotwired/stimulus';

/*
 * Case "Tout sélectionner" au-dessus des cases à cocher "niveaux concernés" du formulaire
 * Examen : coche/décoche tous les niveaux d'un coup, et reflète l'état (coché / partiel /
 * décoché) quand l'utilisateur clique niveau par niveau.
 */
export default class extends Controller {
    static targets = ['toggle', 'level'];

    connect() {
        this.sync();
    }

    toggleAll() {
        this.levelTargets.forEach((checkbox) => {
            checkbox.checked = this.toggleTarget.checked;
        });
        this.sync();
    }

    onLevelChange() {
        this.sync();
    }

    sync() {
        const total = this.levelTargets.length;
        const checked = this.levelTargets.filter((c) => c.checked).length;

        this.toggleTarget.checked = total > 0 && checked === total;
        this.toggleTarget.indeterminate = checked > 0 && checked < total;
    }
}
