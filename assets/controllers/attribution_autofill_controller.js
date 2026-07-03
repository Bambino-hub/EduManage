import { Controller } from '@hotwired/stimulus';

/*
 * Dans le formulaire de nouvelle attribution, pré-sélectionne la matière dès
 * que l'enseignant est choisi, à partir d'un data-matiere-id devinée côté
 * serveur (voir SpecialiteMatiereMatcher) et posé sur chaque <option>.
 * Reste une simple suggestion : l'utilisateur peut toujours changer la matière.
 */
export default class extends Controller {
    static targets = ['enseignant', 'matiere'];

    syncMatiere() {
        const selected = this.enseignantTarget.options[this.enseignantTarget.selectedIndex];
        const matiereId = selected?.dataset.matiereId;
        if (!matiereId || !this.hasMatiereTarget) {
            return;
        }

        const matchingOption = Array.from(this.matiereTarget.options)
            .find((option) => option.value === matiereId);
        if (!matchingOption) {
            return;
        }

        this.matiereTarget.value = matiereId;
        this.matiereTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
